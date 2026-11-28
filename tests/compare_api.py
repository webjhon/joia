"""Compare local HTTP contracts and paid calls with a simulated OpenAI upstream."""

import argparse
import contextlib
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime
from http.cookiejar import CookieJar
import io
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tarfile
import tempfile
import time
from urllib.error import HTTPError
from urllib.request import HTTPCookieProcessor, ProxyHandler, Request, build_opener
import uuid
from zoneinfo import ZoneInfo


ROOT = Path(__file__).resolve().parent.parent
APP_FILES = ("teste_openai.php", "includes", "treinamento.txt", "upload_temp.php")
BASELINE = "0f575aff0dc734c31dfab2bc4713daaf4099f4e9"
CONVERSATION = "comparacao-sintetica"
REPORT_TURNS = 32


class Client:
    def __init__(self, origin, session=None):
        self.origin = origin
        self.session = session
        self.opener = build_opener(ProxyHandler({}), HTTPCookieProcessor(CookieJar()))

    def send(self, *, stream=False, **fields):
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/x-ndjson" if stream else "application/json",
        }
        body = json.dumps({"conversation_id": CONVERSATION, **fields}).encode("utf-8")
        return self.open("/teste_openai.php", body, headers)

    def open(self, path, body=None, headers=None):
        headers = dict(headers or {})
        if self.session:
            headers["Cookie"] = f"PHPSESSID={self.session}"
        try:
            return self.opener.open(Request(self.origin + path, body, headers), timeout=5)
        except HTTPError as error:
            return error

    def answer(self, **fields):
        with self.send(**fields) as response:
            assert response.status == 200, (response.status, response.read())
            return json.load(response)

    def events(self, **fields):
        start = time.monotonic()
        with self.send(stream=True, **fields) as response:
            assert response.headers.get_content_type() == "application/x-ndjson", response.read()
            return [(time.monotonic() - start, json.loads(line)) for line in response]


def stage_app(destination, revision):
    destination.mkdir()
    if revision:
        legacy_prompt = subprocess.check_output(
            ["git", "ls-tree", "--name-only", revision, "--", "NEGATIVEPROMPTS.TXT"], cwd=ROOT, text=True
        ).splitlines()
        content = subprocess.check_output(["git", "archive", revision, *APP_FILES, *legacy_prompt], cwd=ROOT)
        with tarfile.open(fileobj=io.BytesIO(content)) as archive:
            archive.extractall(destination, filter="data")
        return

    for name in APP_FILES:
        source = ROOT / name
        target = destination / name
        if source.is_dir():
            shutil.copytree(source, target)
        else:
            shutil.copy2(source, target)


def php_command(args):
    command = [args.php, "-n"]
    if args.extension_dir:
        command += ["-d", f"extension_dir={args.extension_dir}"]
    for function, extension in (("ctype_digit", "ctype"), ("iconv", "iconv"), ("mb_substr", "mbstring")):
        probe = subprocess.run([*command, "-r", f"exit(function_exists('{function}') ? 0 : 1);"], check=False)
        if probe.returncode:
            command += ["-d", f"extension={extension}"]
    return command


@contextlib.contextmanager
def app_server(directory, command, settings):
    for private in ("memory", "sessions", "metrics"):
        (directory / private).mkdir(mode=0o700)

    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]

    environment = {
        key: value for key, value in os.environ.items()
        if not key.startswith("JOIA_") and key not in {"OPENAI_API_KEY", "PHP_CLI_SERVER_WORKERS"}
    }
    environment.update({
        "OPENAI_API_KEY": "teste-sem-rede",
        "JOIA_TEST_CALLS": str(directory / "calls.jsonl"),
        "JOIA_MEMORY_DIR": str(directory / "memory"),
        "JOIA_METRICS_DIR": str(directory / "metrics"),
        "JOIA_API_PRICES_JSON": json.dumps({"gpt-4o-mini": {"input": 0.15, "cached_input": 0.075, "output": 0.60}}),
    })
    environment.update(settings)
    config = [
        "-d", f"session.save_path={directory / 'sessions'}",
        "-d", f"auto_prepend_file={ROOT / 'tests/fixtures/openai.php'}",
        "-S", f"127.0.0.1:{port}", "-t", str(directory / "web"),
    ]
    with (directory / "server.log").open("w+") as log:
        server = subprocess.Popen([*command, *config], env=environment, stdout=log, stderr=log)
        try:
            for _ in range(150):
                if server.poll() is not None:
                    break
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.02)
            else:
                raise RuntimeError("O servidor de teste não iniciou.")
            if server.poll() is not None:
                log.seek(0)
                raise RuntimeError(log.read())
            yield f"http://127.0.0.1:{port}"
        finally:
            if server.poll() is None:
                server.terminate()
                server.wait(timeout=5)


def calls(directory):
    path = directory / "calls.jsonl"
    return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []


def stage_teacher(directory):
    session = secrets.token_hex(16)
    data = f's:20:"{CONVERSATION}";a:2:{{s:5:"stage";s:7:"welcome";s:5:"admin";s:6:"choose";}}'
    assert len(CONVERSATION) == 20
    (directory / "sessions" / f"sess_{session}").write_text(f'conversations|a:1:{{{data}}}')
    return session


def measure_report(directory, origin, student_code, compact):
    identity = student_code.split("-", 1)[0]
    memory_file = directory / "memory" / f"aluno_{identity}.json"
    memory = json.loads(memory_file.read_text())
    # Use exactly the same report evidence in all runs.
    history = memory["history"] = []
    for index in range(REPORT_TURNS):
        timestamp = f"2026-10-{index % 22 + 1:02d}T10:00:00Z"
        history += [
            {"role": "user", "content": f"Exercício sintético {index}. Por que esse contêiner não tem alinhamento?", "timestamp": timestamp},
            {"role": "assistant", "content": f"Verificação sintética {index}. Compare o eixo e as posições antes e depois.", "timestamp": timestamp},
        ]
    memory_file.write_text(json.dumps(memory, ensure_ascii=False))
    teacher = Client(origin, stage_teacher(directory))
    report = teacher.answer(mensagem="1")
    assert report["etapa"] == "admin_report" and "Síntese do percurso" in report["resposta"]
    report_call = calls(directory)[-1]
    assert report_call["kind"] == "report"
    assert report_call["model"] == "gpt-4o-mini"
    json_input = report_call["messages"][-1]["content"]
    evidence = json.loads(json_input)
    assert evidence["perfil"] == memory["profile"]
    if compact:
        assert evidence["colunas"] == ["role", "content", "timestamp"]
        rows = [list(item.get(column) for column in evidence["colunas"]) for item in history]
        assert evidence["conversas"] == rows
        assert evidence["atividade"]
    else:
        assert evidence["conversas"] == history
    before_pdf = len(calls(directory))
    link = teacher.answer(mensagem="gerar PDF")["download"]
    with teacher.open(f"/{link}") as pdf:
        assert pdf.headers.get_content_type() == "application/pdf" and pdf.read(4) == b"%PDF"
    assert len(calls(directory)) == before_pdf
    return len(json_input.encode("utf-8"))


def exercise(directory, origin, optimized, flags_disabled=False):
    student = Client(origin)
    assert student.answer(acao="novo")["etapa"] == "welcome"
    assert student.answer(mensagem="Olá")["etapa"] == "welcome"
    start_signup = len(calls(directory))
    signup_started = time.monotonic()
    for question, next_stage in (("Pessoa Sintética", "name"), ("Turma de teste", "class"), ("Unidade sintética", "conversation")):
        access = student.answer(mensagem=question)
        assert access["etapa"] == next_stage and access["resposta"]
    signup_ms = (time.monotonic() - signup_started) * 1000
    signup = calls(directory)[start_signup:]
    expect_local = optimized and not flags_disabled
    assert [call["kind"] for call in signup] == ([] if expect_local else ["onboarding"] * 3)
    code = access["codigo"]

    quality_scenarios = [
        "O que é um eixo transversal?",
        "Como combinar alinhamento, direção e espaçamento em um exercício com colunas?",
        "Com a mesma coluna de antes, o que devo observar primeiro?",
        "Continuo com dificuldade em reconhecer a direção do contêiner.",
    ]
    for question in quality_scenarios:
        assert student.answer(mensagem=question)["resposta"]
    last_call = calls(directory)[-1]
    assert last_call["kind"] == "chat"
    assert last_call["model"] == "gpt-4o-mini"
    actual_context = [m["content"] for m in last_call["messages"] if m["role"] == "user"]
    assert actual_context == quality_scenarios

    native = "No NativeWind quero alinhar a View em coluna. Qual classe devo usar?"
    events = student.events(mensagem=native)
    paragraphs = [(when, event) for when, event in events if event["type"] != "phase"]
    assert [event["type"] for _, event in paragraphs] == ["start", "delta", "delta", "done"]
    assert paragraphs[-1][0] > paragraphs[1][0], events
    assert 'className="flex-col items-center"' in paragraphs[-1][1]["resposta"]
    assert "className=" not in paragraphs[1][1]["text"]
    assert calls(directory)[-1]["stream_usage"] is optimized

    history_count = len(student.answer(acao="estado")["historico"])
    before_failure = len(calls(directory))
    with student.send(mensagem="Falha de API sintética") as error:
        assert error.status >= 400, error.status
    assert len(calls(directory)) == before_failure + 1
    assert len(student.answer(acao="estado")["historico"]) == history_count

    before_duplicate = len(calls(directory))
    request_id = str(uuid.uuid4())
    first = student.answer(mensagem="Como verificar o efeito da alteração?", request_id=request_id)
    repeated = student.answer(mensagem="Como verificar o efeito da alteração?", request_id=request_id)
    assert repeated["resposta"] == first["resposta"]
    repeat_calls = len(calls(directory)) - before_duplicate
    deduplicates = optimized and not flags_disabled
    assert repeat_calls == (1 if deduplicates else 2), repeat_calls
    current_history_count = len(student.answer(acao="estado")["historico"])
    expected_count = history_count + (2 if deduplicates else 4)
    assert current_history_count == expected_count
    if deduplicates:
        with student.send(mensagem="Outro texto no mesmo envio", request_id=request_id) as conflict:
            assert conflict.status == 409
        assert len(calls(directory)) - before_duplicate == repeat_calls

    pre_upload = len(calls(directory))
    with student.open("/upload_temp.php") as upload:
        assert "arquivo" in json.load(upload)["error"]
    assert len(calls(directory)) == pre_upload

    report_size = measure_report(directory, origin, code, expect_local)
    resumed = Client(origin).answer(acao="retomar", codigo=code)
    assert resumed["historico"][-1]["content"].startswith("Verificação sintética")
    metrics = [
        json.loads(line)
        for path in (directory / "metrics").glob("*.jsonl")
        for line in path.read_text().splitlines()
    ]
    if optimized:
        assert any(
            item["operation"] == "chat" and item.get("cached_input_tokens") == 2048
            for item in metrics
        )
        if deduplicates:
            assert any(
                item["operation"] == "chat" and item.get("api_requests") == 0
                for item in metrics
            )
        assert any(item["operation"] == "report" and item.get("estimated_cost_usd") for item in metrics)
        assert "Pessoa Sintética" not in json.dumps(metrics, ensure_ascii=False)
    else:
        assert not metrics
    return {
        "cadastro_aceito_requisicoes": len(signup),
        "cadastro_simulado_ms": round(signup_ms, 2),
        "duplo_envio_requisicoes": repeat_calls,
        "relatorio_contexto_bytes": report_size,
    }


def register(origin, name="Pessoa Sintética"):
    student = Client(origin)
    student.answer(acao="novo")
    for message in (name, "Turma de teste", "Unidade sintética"):
        access = student.answer(mensagem=message)
    return student, access["codigo"]


def memory_file(directory, code):
    identity = code.split("-", 1)[0]
    return directory / "memory" / f"aluno_{identity}.json"


def ask_stream(student, question, **fields):
    events = [event for _, event in student.events(mensagem=question, **fields)]
    assert events[0]["type"] == "start" and events[-1]["type"] == "done", events
    deltas = [event["text"] for event in events if event["type"] == "delta"]
    assert "".join(deltas) == events[-1]["resposta"], events
    return events[-1]


def exercise_budget(directory, origin):
    student, _ = register(origin)
    before = len(calls(directory))
    result = student.answer(mensagem="Como alinhar uma coluna?")
    assert result["resposta"] and len(calls(directory)) == before + 1
    assert calls(directory)[-1]["limit"] is None
    assert calls(directory)[-1]["completion_limit"] is None
    teacher = Client(origin, stage_teacher(directory))
    with teacher.send(mensagem="1") as response:
        assert response.status >= 400
    assert len(calls(directory)) == before + 1
    metrics = [
        json.loads(line)
        for path in (directory / "metrics").glob("*.jsonl")
        for line in path.read_text().splitlines()
    ]
    assert any(
        item["operation"] == "report" and item.get("error_code") == "budget"
        and item["api_requests"] == 0
        for item in metrics
    )


def exercise_direct_chat(directory, origin):
    student, code = register(origin)
    student.answer(acao="salvar_anotacoes", anotacoes="Lembrete sintético", revisao=0)
    path = memory_file(directory, code)
    older = json.loads(path.read_text())
    older["snippet_quota"] = {
        "day": datetime.now(ZoneInfo("America/Sao_Paulo")).date().isoformat(),
        "count": 3,
    }
    earlier = [
        {"role": "user", "content": "Meu exemplo de estudo histórico usa um totalizador de maçãs."},
        {"role": "assistant", "content": "Usaremos três maçãs para começar a testar o totalizador."},
    ]
    older["history"] = earlier.copy()
    path.write_text(json.dumps(older, ensure_ascii=False))

    resumed = Client(origin)
    active = resumed.answer(acao="retomar", codigo=code, mostrar_historico=False)
    assert "historico" not in active and active["anotacoes"] == "Lembrete sintético"
    assert "historico" not in resumed.answer(acao="estado", mostrar_historico=False)

    topics = (
        ("Entregue um componente completo em React Native com NativeWind.", "jsx"),
        ("Mostre uma animação Animated completa, com imports.", "tsx"),
        ("Mostre o programa completo em Python, com duas caixas de código.", "python"),
        ("Escreva a solução completa em SQL.", "sql"),
    )
    for index, (question, language) in enumerate(topics):
        request_id = str(uuid.uuid4())
        before = len(calls(directory))
        if index % 2 == 0:
            reply = ask_stream(resumed, question, request_id=request_id)
        else:
            reply = resumed.answer(mensagem=question, request_id=request_id)
        trace = calls(directory)[before:]
        assert len(trace) == 1 and trace[0]["kind"] == "chat", trace
        call = trace[0]
        assert call["limit"] is None and call["completion_limit"] is None, call
        assert reply["resposta"] == call["simulated_text"], reply
        assert reply["resposta"].count("```") == 4, reply
        assert f"```{language}" in reply["resposta"], reply
        assert len(reply["resposta"].split("```")[1].splitlines()[1:]) > 4, reply
        roles = [(item["role"], item["content"]) for item in call["messages"]]
        assert roles[-1] == ("user", question)
        assert [(row["role"], row["content"]) for row in earlier] == [
            role for role in roles if role[0] != "system"
        ][:2]
        if index:
            assert ("assistant", previous_reply) in roles
        previous_reply = reply["resposta"]
        system = "\n".join(content for role, content in roles if role == "system").lower()
        assert "histórico" in system
        assert all(term not in system for term in (
            "restrições complementares", "cota", "quatro linhas", "sem incluir código",
            "sem caixa ou implementação", "não apresente urls", "só dê pistas",
        )), system
        saved = json.loads(path.read_text())
        assert saved["history"][-1]["content"] == previous_reply and len(saved["history"]) == 4 + 2 * index
        replay = resumed.answer(mensagem=question, request_id=request_id)
        assert replay["resposta"] == previous_reply and len(calls(directory)) == before + 1

    # A request beyond the old input cap reaches the model intact.
    long_question = "Revise meu comentário de programação. " + "Texto sintético. " * 280 + "Fim."
    assert len(long_question) > 4000
    answer = resumed.answer(mensagem=long_question)
    last = calls(directory)[-1]
    assert last["messages"][-1] == {"role": "user", "content": long_question}
    assert answer["resposta"] == last["simulated_text"]

    # Send a complete response containing phrasing the former review omitted.
    question = "Mostre a resposta de referência sem retoques."
    untouched = resumed.answer(mensagem=question)
    trace = calls(directory)[-1]
    assert untouched["resposta"] == trace["simulated_text"]
    assert untouched["resposta"].startswith("\n4.") and untouched["resposta"].endswith("ou\n")
    assert "Aqui está um exemplo" in untouched["resposta"]

    partials = (
        ("Simule uma resposta longa interrompida no meio.", True),
        ("Simule uma resposta longa interrompida no meio.", False),
        ("Simule uma resposta parcial sinalizada pelo provedor.", True),
        ("Simule o fim do fluxo antes da conclusão do provedor.", True),
    )
    for question, streamed in partials:
        before = len(calls(directory))
        request_id = str(uuid.uuid4())
        if streamed:
            partial = ask_stream(resumed, question, request_id=request_id)
        else:
            partial = resumed.answer(mensagem=question, request_id=request_id)
        only_call, = calls(directory)[before:]
        assert only_call["kind"] == "chat" and partial["resposta"] == only_call["simulated_text"], partial
        assert partial.get("interrompida") is True and partial["resposta"].endswith("return total"), partial
        assert json.loads(path.read_text())["history"][-1]["content"] == partial["resposta"]
        replay = resumed.answer(mensagem=question, request_id=request_id)
        assert replay["resposta"] == partial["resposta"] and replay.get("interrompida") is True
        assert len(calls(directory)) == before + 1

    other, _ = register(origin, "Outro Perfil Sintético")
    other.answer(mensagem="Faça um exemplo com peras.")
    other_messages = calls(directory)[-1]["messages"]
    assert not any("totalizador de maçãs" in item["content"] for item in other_messages), other_messages
    assert not any("Lembrete sintético" in item["content"] for item in other_messages), other_messages
    with Client(origin).send(acao="retomar", codigo="0" * 32 + "-" + "0" * 32) as denied:
        assert denied.status == 401


def exercise_concurrent_history(directory, origin, second_origin):
    student, code = register(origin)
    another_session = Client(second_origin)
    another_session.answer(acao="retomar", codigo=code, mostrar_historico=False)
    questions = tuple(f"Mostre o exemplo {variant} do teste concorrente de histórico." for variant in ("A", "B"))
    ids = [str(uuid.uuid4()), str(uuid.uuid4())]
    clients = (student, another_session)
    with ThreadPoolExecutor(max_workers=2) as pool:
        submitted = [pool.submit(ask_stream, client, question, request_id=rid)
                     for client, question, rid in zip(clients, questions, ids)]
        results = [future.result()["resposta"] for future in submitted]
    assert all("```" in answer for answer in results), results

    updated = json.loads(memory_file(directory, code).read_text())
    assert len(updated["history"]) == 4
    pairs = list(zip(updated["history"][::2], updated["history"][1::2]))
    assert all(user["role"] == "user" and model["role"] == "assistant" for user, model in pairs)
    assert {(user["content"], model["content"]) for user, model in pairs} == set(zip(questions, results))
    before_replays = len(calls(directory))
    for client, question, rid, answer in zip(clients, questions, ids, results):
        assert client.answer(mensagem=question, request_id=rid)["resposta"] == answer
    assert len(calls(directory)) == before_replays


def exercise_teaching_materials(directory, origin):
    student, _ = register(origin)

    questions = (
        ("Como funciona o salto com translateY no Animated?", "O sapo que pula"),
        ("Como uso a escala do ícone com Animated a partir da pontuação?", "Conquista desbloqueada"),
        ("Como validar a janela verde no Sapo na Ponte?", "Sapo na Ponte"),
        ("Qual a diferença entre npm e npx no Node.js?", "Bem-vindo ao Node.js"),
        ("Como testar NativeWind com Expo?", "NativeWind com Expo"),
        ("Como a View pai controla o layout no React Native?", "React Native Visual"),
        ("Como montar minha primeira tela com TypeScript no Expo Go?", "Minha Primeira Tela com Expo"),
        ("Como gerar APK com Expo sem Git?", "Gerando um APK"),
        ("Como navegar entre duas telas no React Native?", "Criando várias páginas"),
        ("E como aplico isso?", "Criando várias páginas"),
        ("Pode explicar melhor?", "Criando várias páginas"),
    )
    for question, title in questions:
        request_id = str(uuid.uuid4())
        result = student.answer(mensagem=question, request_id=request_id)
        refs = result["referencias"]
        assert 1 <= len(refs) <= 2 and any(title in ref for ref in refs), (question, result)
        assert all("http" not in ref for ref in refs)
        prompts = calls(directory)[-1]["messages"]
        teaching = next(item["content"] for item in prompts if "referências didáticas" in item["content"].lower())
        assert title in teaching and "http" not in teaching
        assert "mantendo as regras pedagógicas" not in teaching

    before_replay = len(calls(directory))
    replay = student.answer(mensagem=question, request_id=request_id)
    assert replay["referencias"] == refs
    assert len(calls(directory)) == before_replay

    unrelated = student.answer(mensagem="Como funciona um índice no SQL?")
    assert "referencias" not in unrelated
    assert all("referências didáticas" not in item["content"].lower() for item in calls(directory)[-1]["messages"])

    streamed = student.events(mensagem="Como separo as telas e uso estado no React Native?")
    assert "referencias" in streamed[-1][1]
    assert all("referencias" not in event for _, event in streamed[:-1])


def exercise_teacher_lookup_and_technical_context(directory, origin):
    student, _ = register(origin)
    jump = ask_stream(student, "Agora me da um exemplo de código de um objeto que pula na tela por exemplo.")
    assert jump["etapa"] == "conversation" and "translateY" in jump["resposta"]
    before_lookup = len(calls(directory))
    known_topics = (
        ("isso está em algum material?", "O sapo que pula"),
        ("Me refiro em algum material do profe joão", "O sapo que pula"),
        ("Mas tu não sabe quais os materiais dele?", "O sapo que pula"),
        ("O profe João tem material sobre criar um APK no Expo?", "Gerando um APK"),
    )
    for question, expected_title in known_topics:
        request_id = str(uuid.uuid4())
        result = ask_stream(student, question, request_id=request_id)
        call = calls(directory)[-1]
        assert any(expected_title in title for title in result["referencias"]), result
        assert call["kind"] == "chat" and result["resposta"] == call["simulated_text"]
        assert any(expected_title in item["content"] for item in call["messages"] if item["role"] == "system")
    assert len(calls(directory)) == before_lookup + len(known_topics)
    before_replay = len(student.answer(acao="estado")["historico"])
    replay = student.answer(mensagem=question, request_id=request_id)
    assert replay["resposta"] == result["resposta"] and replay.get("referencias") == result.get("referencias"), replay
    assert len(student.answer(acao="estado")["historico"]) == before_replay

    unknown = ask_stream(student, "E tem algum material do profe João sobre índices em SQL?")
    assert not unknown.get("referencias"), unknown
    catalog = ask_stream(student, "Quais são todos os materiais do professor João?")
    assert catalog["resposta"] == calls(directory)[-1]["simulated_text"]
    system = "\n".join(item["content"] for item in calls(directory)[-1]["messages"] if item["role"] == "system")
    assert "O sapo que pula" in system and "NativeWind com Expo" in system
    assert len(calls(directory)) == before_lookup + len(known_topics) + 2

    technical, code = register(origin, "Pessoa Fictícia")
    setup = ask_stream(technical, "Quero ligar o valor animado à opacidade de uma View com Animated.")
    assert "opacity: opacidade" in setup["resposta"] and setup["resposta"].count("```") == 2, setup
    path = memory_file(directory, code)
    memory = json.loads(path.read_text())
    for index in range(7):
        memory["history"].extend([
            {"role": "user", "content": f"Como validar a etapa intermediária {index} do exercício?"},
            {"role": "assistant", "content": f"Observe o efeito visual da etapa {index} antes de continuar."},
        ])
    memory["history"][-1]["content"] += '\n\n```jsx\n<View className="p-9" />\n```'
    assert all("opacity: opacidade" not in turn["content"] for turn in memory["history"][-12:])
    path.write_text(json.dumps(memory, ensure_ascii=False))
    resumed = Client(origin)
    resumed.answer(acao="retomar", codigo=code, mostrar_historico=False)

    before_explanation = len(calls(directory))
    explanation = ask_stream(resumed, "O que significa opacity: opacidade naquele exemplo?")
    text = explanation["resposta"].lower()
    assert "opacity" in text and "opacidade" in text and ("propriedade" in text or "chave" in text), explanation
    assert "variável" in text or "valor" in text, explanation
    assert len(calls(directory)) == before_explanation + 1

    broader = "No exemplo anterior de opacidade, por que o valor começa em zero e o que muda durante a animação? Explique em palavras."
    before_broader = len(calls(directory))
    explained = ask_stream(resumed, broader)
    assert "valorErradoDaFixture" not in explained["resposta"] and "invisível" in explained["resposta"], explained
    trace = calls(directory)[before_broader:]
    assert len(trace) == 1 and trace[0]["kind"] == "chat", trace
    system = "\n".join(item["content"] for item in trace[0]["messages"] if item["role"] == "system")
    assert "Fragmento efetivamente exibido na conversa" in system and "Referências técnicas curadas" in system
    assert "const opacidade = useRef(new Animated.Value(0)).current;" in system and "opacity: opacidade" in system
    assert '<View className="p-9" />' not in system

    algorithm = ask_stream(resumed, "Como testar o algoritmo de um acumulador quando a lista está vazia?")
    assert "estado inicial" in algorithm["resposta"], algorithm
    algorithm_system = "\n".join(item["content"] for item in calls(directory)[-1]["messages"] if item["role"] == "system")
    assert "Programação · Decomposição e teste de mesa" in algorithm_system
    assert "Fragmento efetivamente exibido na conversa" not in algorithm_system
    assert "opacity: opacidade" not in algorithm_system and '<View className="p-9" />' not in algorithm_system

    switched = ask_stream(resumed, "Mudando de assunto, como funciona um índice em SQL?")
    assert switched["etapa"] == "conversation" and not switched.get("referencias"), switched
    switched_system = [item["content"] for item in calls(directory)[-1]["messages"] if item["role"] == "system"]
    assert all("Fragmento efetivamente exibido na conversa" not in prompt for prompt in switched_system)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default=shutil.which("php") or "php", help="binário PHP disponível para testes")
    parser.add_argument("--extension-dir", help="diretório com módulos ctype, iconv e mbstring se necessário")
    parser.add_argument("--baseline", default=BASELINE, help="referência Git da arquitetura anterior")
    args = parser.parse_args()
    command = php_command(args)
    scenarios = [
        ("anterior", args.baseline, False, {}),
        ("otimizada", None, True, {}),
        ("otimizada_com_flags_desligadas", None, True, {"JOIA_LOCAL_ONBOARDING": "0", "JOIA_COMPACT_REPORT": "0", "JOIA_ADAPTIVE_RAG": "0", "JOIA_REQUEST_DEDUP": "0"}),
    ]
    measurements = {}
    with tempfile.TemporaryDirectory(prefix="joia-comparacao-") as temp:
        for name, revision, optimized, settings in scenarios:
            directory = Path(temp) / name
            directory.mkdir()
            stage_app(directory / "web", revision)
            with app_server(directory, command, settings) as origin:
                measurements[name] = exercise(directory, origin, optimized, bool(settings))

        directory = Path(temp) / "orcamento"
        directory.mkdir()
        stage_app(directory / "web", None)
        settings = {"JOIA_TOKEN_BUDGETS_JSON": '{"chat":{"input":1,"output":1},"report":{"input":1}}'}
        with app_server(directory, command, settings) as origin:
            exercise_budget(directory, origin)

        directory = Path(temp) / "chat-direto"
        directory.mkdir()
        stage_app(directory / "web", None)
        with app_server(directory, command, {}) as origin:
            exercise_direct_chat(directory, origin)

        directory = Path(temp) / "historico-concorrente"
        second_server = directory / "segundo-servidor"
        second_server.mkdir(parents=True)
        stage_app(directory / "web", None)
        stage_app(second_server / "web", None)
        shared = {"JOIA_MEMORY_DIR": str(directory / "memory"), "JOIA_TEST_CALLS": str(directory / "calls.jsonl")}
        with app_server(directory, command, {}) as origin, app_server(second_server, command, shared) as second_origin:
            exercise_concurrent_history(directory, origin, second_origin)

        directory = Path(temp) / "referencias-professor"
        directory.mkdir()
        stage_app(directory / "web", None)
        with app_server(directory, command, {}) as origin:
            exercise_teaching_materials(directory, origin)
            exercise_teacher_lookup_and_technical_context(directory, origin)

    assert measurements["otimizada"]["relatorio_contexto_bytes"] < measurements["anterior"]["relatorio_contexto_bytes"]
    assert measurements["otimizada_com_flags_desligadas"]["relatorio_contexto_bytes"] == measurements["anterior"]["relatorio_contexto_bytes"]
    print(json.dumps(measurements, ensure_ascii=False, indent=2))
    print("Comparação HTTP concluída com dados sintéticos. Tamanho em bytes e contagem de chamadas são medidos; faturamento e qualidade do modelo real não foram testados.")


if __name__ == "__main__":
    main()
