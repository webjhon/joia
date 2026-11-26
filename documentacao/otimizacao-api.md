# Consumo e desempenho da API da JOIA

Este relatório acompanha a [arquitetura de IA](inteligencia-artificial.html), o [backend](backend.html) e a [geração de relatórios](dados-relatorios.html). A comparação usa a revisão `0f575af` como linha de base. Os procedimentos de medição usam dados sintéticos e mantêm o histórico real fora dos testes.

## 1. Linha de base auditada

A aplicação persiste cada percurso em JSON. O identificador do aluno vincula a memória à sessão, as anotações e o índice SQLite opcional. O chat passa pelo filtro pedagógico antes de gravar a resposta integral. O streaming publica parágrafos autônomos, preserva o que já foi exibido e apresenta o texto final depois da validação. O ajuste pedagógico pode chamar o modelo uma segunda vez quando necessário.

| Caminho | API e chamadas por operação | Evidência na revisão original |
| --- | --- | --- |
| Conversação | Chat Completions, `gpt-4o-mini`, uma chamada inicial | O sistema envia os documentos pedagógicos, instruções da operação, perfil, histórico recente e pergunta. O streaming lê os deltas antes da persistência. |
| Reformulação pedagógica | Chat Completions, mesmo modelo, até uma chamada adicional | O servidor verifica a saída e pode solicitar uma continuação ou reformulação sem código. O resultado continua sujeito à validação. |
| Cadastro | Chat Completions, mesmo modelo, uma chamada por turno de identificação | `onboardingValue()` já extrai e valida o dado localmente. O modelo redige a resposta conforme o estado. |
| Memória vetorial | Embeddings, `text-embedding-3-small`, no máximo um lote quando há entradas a vetorizar | Com diretório configurado e histórico suficiente, o SQLite recupera vetores por aluno, modelo e SHA-256 do texto. O lote contém a pergunta atual e até seis perguntas históricas ausentes, preservadas as entradas já indexadas. |
| Relatório pedagógico | Chat Completions, mesmo modelo, uma chamada por solicitação | O perfil e o histórico integral são serializados como objetos com papéis e carimbos de data repetidos. O PDF é montado pelo PHP a partir da análise. |
| Arquivos enviados | Zero | O upload legado recebe imagens e áudio, move o arquivo e remove arquivos antigos. A interface atual do chat não chama essa rota nem encaminha anexos para o modelo. |
| Estado, retomada, notas, PDF e avatar | Zero | Sessão, gravação e leitura do caderno, formatação de PDF e componentes do navegador são locais. |

Referências no repositório: `teste_openai.php`, `includes/memoria_vetorial.php`, `includes/streaming.php`, `upload_temp.php` e `index.html` na revisão `0f575af`.

### Evidências de desperdício

- O cadastro encaminhava dados já extraídos ao modelo para redigir confirmações rotineiras. A validação de formato e a progressão por etapa já eram ações locais.
- Com RAG disponível e candidato histórico, saudações e dúvidas independentes percorriam a mesma recuperação das perguntas que citavam dificuldades anteriores. O cache evitava gerar vetores idênticos para o mesmo aluno, mas ainda podia gerar o vetor de uma mensagem sem utilidade como consulta.
- O relatório enviava os textos integrais e as chaves de cada elemento em JSON. O modelo recebia ocorrências repetidas de papel e data que podem ser representadas de maneira mais compacta sem eliminar as falas.
- Os documentos de instruções ocupavam **17.325 bytes** antes de serialização e de formatação da API: **10.498** de `treinamento.txt` e **6.827** de `NEGATIVEPROMPTS.TXT`. Eles já iniciavam cada chamada de chat. As instruções específicas de alguns fluxos ficavam misturadas com perfil e histórico variáveis mais adiante.
- A implementação não requisitava o bloco de uso no streaming, não consumia `usage` nas chamadas JSON e não mantinha série local de consumo. Assim, não havia comprovação de acerto no cache, custo por conversa ou distribuição de latência em produção.
- A interface bloqueava novas interações durante o envio. O backend não distinguia a repetição de uma mesma operação pela rede de um novo pedido intencional com o mesmo texto. A auditoria do código indica a possibilidade de cobrança dupla, mas não comprova ocorrência em produção.

### Hipóteses que precisam de dados de produção

O volume real de chamadas reformuladas, a frequência de timeouts, o efeito do RAG sobre o tempo de resposta, os acertos no cache de prefixo e o custo por aluno ou por semana dependem de tráfego medido. A duração do streaming também depende do buffering da hospedagem. Não foi identificada etapa de análise de documentos na API atual. Criar tal funcionalidade geraria uma operação nova e não é uma economia mensurável nesta auditoria.

### Medida reproduzível do prefixo de instruções

Medi o conteúdo de texto na revisão `0f575af` com `tiktoken` 0.12.0 e a codificação `o200k_base`, usada pelo `gpt-4o-mini`. A ferramenta foi usada apenas no teste local, fora do checkout. A contagem exclui a estrutura das mensagens no protocolo da API.

| Material fixo | Bytes | Tokens do conteúdo |
| --- | ---: | ---: |
| `treinamento.txt` | 10.498 | 2.185 |
| `NEGATIVEPROMPTS.TXT` | 6.827 | 1.394 |
| Mensagem de sistema montada com os dois documentos e cabeçalhos | 17.369 | 3.592 |

Esse custo de contexto é preservado pela decisão de manter as instruções completas. Comparar tokens em cache requer o `usage` real do provedor, mesmo quando o texto fixo é idêntico.

## 2. Otimizações e critérios de qualidade

**Instruções e cache.** Os dois documentos pedagógicos permanecem integrais. A montagem conserva primeiro o conteúdo estável e posiciona as instruções específicas de cada operação antes de perfil, memória recuperada, histórico e pergunta. A recomendação de cache é de prefixo estável, sem inserir identificadores do estudante na chave nem armazenar respostas compartilhadas. O caching da OpenAI depende de coincidência exata, elegibilidade do modelo e do cache do serviço. A métrica de confirmação é `usage.prompt_tokens_details.cached_tokens`.

**Memória adaptativa.** A decisão de consultar memória é local. Só mensagens reconhecidas com alta confiança como independentes deixam de chamar a recuperação. Referências, continuidade, dificuldades recorrentes e casos ambíguos mantêm o caminho semântico. Os dados por estudante, a dimensão dos vetores e os limiares de recuperação existentes são preservados. O histórico recente permanece disponível. A montagem evita enviar novamente um trecho recuperado que já aparece no histórico enviado.

**Orçamento de contexto.** Registre a estimativa de entrada de forma distinta do `usage` medido. O orçamento é por operação e a reserva de saída deve preservar o espaço existente por padrão. Instruções obrigatórias, perfil, pergunta e histórico necessário são prioritários. Configure limites só depois de comparar cargas reais. Qualquer teto escolhido deve ser testado com diálogos longos antes de instalar em uma turma.

**Cadastro e chamadas repetidas.** Confirmações claras de etapas já validadas localmente podem seguir por uma resposta determinística. Mensagens ambíguas continuam no fluxo do LLM. O identificador opcional de operação permite reconhecer o reenvio da mesma tentativa no escopo autenticado. O servidor conserva até 16 identificadores na sessão da conversa. Uma nova pergunta deliberada pode ter o mesmo texto e um identificador diferente. Não há reenvio automático de chamadas pagas depois de timeout.

**Relatórios.** Estatísticas objetivas são calculadas localmente. A apresentação do histórico ao modelo reduz metadados repetidos sem resumir ou eliminar o texto original. O LLM continua responsável pela interpretação qualitativa. O PDF local e o armazenamento do percurso continuam disponíveis no fluxo docente.

## 3. Observabilidade privada

Habilite as métricas com um diretório já existente, acessível à conta que executa o PHP e fora da raiz pública e do checkout. A coleta usa o retorno das chamadas existentes. Os eventos são gravados em JSONL por dia UTC com permissão 0600. Se outro processo estiver gravando ou o caminho for inválido, a coleta pode omitir eventos para não bloquear a resposta. A consulta administrativa roda só no CLI e devolve 404 por HTTP.

| Variável | Finalidade |
| --- | --- |
| `JOIA_METRICS_DIR` | Diretório privado preexistente, com permissão 0700, fora do checkout e da raiz pública. Sem valor, a coleta fica desativada. |
| `JOIA_API_PRICES_JSON` | Tabela em USD por milhão de tokens. Ao sobrescrever um modelo, informe os campos aplicáveis `input`, `cached_input` e `output` no mesmo objeto. |
| `JOIA_METRICS_TZ` | Fuso dos períodos no CLI. Padrão `America/Sao_Paulo`. |
| `JOIA_ADAPTIVE_RAG=0` | Retoma a decisão de recuperação anterior, mantendo a configuração existente do SQLite. |
| `JOIA_COMPACT_REPORT=0` | Restaura a representação anterior do histórico integral no relatório. |
| `JOIA_LOCAL_ONBOARDING=0` | Volta à geração pelo modelo para mensagens claras de cadastro. |
| `JOIA_REQUEST_DEDUP=0` | Desativa o reaproveitamento da mesma operação identificada, útil durante rollback seletivo. |
| `JOIA_TOKEN_BUDGETS_JSON` | Orçamento opcional por operação. A chave `input` vale para `chat`, `onboarding`, `pedagogical_retry`, `report` e `embedding`. A chave `output` vale para as operações de Chat Completions. |

Use um objeto JSON por operação. O exemplo abaixo mostra o formato para configuração do ambiente.

```json
{
  "chat": {"input": 96000, "output": 900},
  "report": {"output": 1600},
  "embedding": {"input": 30000}
}
```

O orçamento de entrada usa uma aproximação conservadora com bytes UTF-8 e margem para estrutura da mensagem. Esse valor constitui um limite local, sem relação direta com o número de tokens faturáveis. A checagem preserva o material indispensável. Para chamadas de chat que ultrapassem um teto optativo, o servidor interrompe o envio ao modelo em vez de descartar o contexto. Sem essa configuração, o fluxo conserva os limites de saída existentes e não adiciona teto de entrada.

Consulta local:

```sh
php scripts/usage.php day
php scripts/usage.php week 2026-11-26
php scripts/usage.php month 2026-11-01
```

Use as métricas para comparar chamadas, tokens, acerto de cache e duração na mesma finalidade. Para avaliar a contribuição da recuperação, separe as cargas com RAG ativo, descartado localmente ou indisponível. A coleta não deve guardar o conteúdo das conversas, nomes, códigos de acesso, cabeçalhos ou a chave da API.

Chat Completions disponibiliza `usage.prompt_tokens`, `usage.completion_tokens`, `usage.total_tokens` e, quando presente, `usage.prompt_tokens_details.cached_tokens`. Com `stream_options.include_usage`, o serviço envia um evento adicional antes de `[DONE]` com `choices` vazio. Uma interrupção pode impedir a chegada desse evento. Para embeddings, são publicados `usage.prompt_tokens` e `usage.total_tokens`. Uma requisição sem `usage` permanece com consumo não medido. O CLI contabiliza chamadas sem estimativa de custo, inclusive quando faltam os campos de cache necessários para a tarifa de chat. A ausência de um campo não comprova consumo zero. [Chat Completions](https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create) · [Embeddings](https://developers.openai.com/api/reference/resources/embeddings/methods/create) · [Prompt caching](https://developers.openai.com/api/docs/guides/prompt-caching)

Os preços consultados nas páginas dos modelos foram USD **0,15** de entrada, **0,075** de entrada em cache e **0,60** de saída por milhão de tokens no `gpt-4o-mini`, e **0,02** de entrada por milhão no `text-embedding-3-small`. Para chamadas com uso disponível, a estimativa de custo é a soma de entrada sem cache, entrada em cache e saída, cada uma ponderada pela sua tarifa. Compare com a cobrança oficial da conta para auditoria financeira. [GPT-4o mini](https://developers.openai.com/api/docs/models/gpt-4o-mini) · [Embeddings small](https://developers.openai.com/api/docs/models/text-embedding-3-small)

## 4. Comparação e evidências

As comparações locais separam tamanho de material enviado, quantidade de requisições e respostas de fixtures. Tokens retornados pela API, qualidade do modelo real em diálogos longos e latência de produção só podem ser atestados com observações na hospedagem. Respostas sintéticas são úteis para testar o encadeamento e os limites, sem representar uma avaliação cega das respostas do modelo.

| Cenário | Linha de base esperada | O que observar após a alteração |
| --- | --- | --- |
| Saudação curta e pergunta autônoma | Uma geração e possível recuperação | Geração com contexto recente preservado e recuperação dispensada só quando reconhecida com segurança. |
| Continuação, referência histórica e dificuldade recorrente | RAG disponível conforme o histórico | A mesma memória relevante, mesma seleção por estudante e fallback para histórico recente se SQLite ou embeddings falharem. |
| Dúvida complexa, leitura de código, pedido de solução pronta e tentativa de manipulação | Contexto e filtro pedagógico | Prioridade das regras, resposta condizente com a dúvida, revisão quando necessária e publicação somente após validação. |
| Cadastro claro e ambíguo | Uma geração por mensagem | Confirmação local clara. Dúvida ou outra intenção segue para o LLM. |
| Relatório acadêmico | Histórico integral serializado e uma geração | Textos e ordem preservados, metadados mais compactos, mesmo PDF e um único pedido de análise. |
| Upload legado e documentos | Zero chamadas de IA | Upload mantém sua finalidade. A arquitetura continua sem enviar anexos ao LLM. |
| Falhas e repetição de requisição | Fallback sem telemetria de consumo | Falhas sem sucessos fictícios, reenvio identificado e nenhum retry automático cobrado. |

### Comparação das operações

| Medida em dados sintéticos | Anterior | Otimizada |
| --- | ---: | ---: |
| Chamadas ao modelo nas três etapas claras de cadastro | 3 | 0 |
| Chamadas ao modelo em dois envios com o mesmo identificador | 2 | 1 |
| Lotes de embeddings em cinco cenários com cache frio | 5 | 3 |
| Textos nos lotes de embeddings | 19 | 9 |
| Material JSON em relatório com as mesmas 64 mensagens | 9.052 bytes | 7.388 bytes |

Nos três cenários que precisavam da recuperação (continuação, dúvida complexa e dificuldade recente), as duas memórias selecionadas foram as mesmas. A saudação e a definição autônoma dispensaram a busca. O cache vetorial, a reparação de entradas inválidas e o isolamento por aluno também foram exercitados com SQLite. O teste HTTP verificou o contexto de continuidade, a regra de código do NativeWind, a reformulação, o streaming, a recuperação após timeout, a geração local do PDF, o teto de entrada e a reversão por variáveis de ambiente.

Em uma execução local, as três etapas claras de cadastro levaram 10,73 ms na linha de base e 9,90 ms na versão otimizada com transporte simulado. O teste não inclui a rede nem o processamento da OpenAI, então o resultado mede somente esse ambiente de teste. A redução de três chamadas externas elimina esse tempo de rede e inferência no cadastro claro, mas a diferença na latência da Hostinger ainda precisa de medição.

Para repetir a comparação, use Python 3.12, PHP com `ctype`, `iconv` e `mbstring`, e PDO SQLite para o segundo teste. A simulação de HTTP inicia um PHP local sem cURL e injeta o transporte de teste.

```sh
python3 tests/compare_api.py
baseline=$(mktemp)
git show 0f575af:includes/memoria_vetorial.php > "$baseline"
JOIA_ADAPTIVE_RAG=1 php tests/compare_rag.php "$baseline"
JOIA_ADAPTIVE_RAG=1 php tests/compare_rag.php includes/memoria_vetorial.php
rm "$baseline"
```

### Comparação local de um relatório sintético

Usei 12 interações fictícias em 12 dias, totalizando 24 mensagens textuais. O formato compacto preservou cada papel, texto e data, mais os agregados calculados localmente. Conteúdo tokenizado com `o200k_base`, com o prompt de layout incluído na versão atual.

| Medida | Revisão original | Formato compacto |
| --- | ---: | ---: |
| Material JSON das evidências | 3.643 bytes | 3.139 bytes |
| Tokens do conteúdo das evidências | 1.031 | 971 |
| Tokens de entrada do conteúdo, incluindo instruções e legenda | 4.733 | 4.695 |
| Estimativa ilustrativa da entrada integral a USD 0,15 por milhão, sem cache | USD 0,00070995 | USD 0,00070425 |
| Chamadas ao modelo previstas pela rota | 1 | 1 |

O conteúdo medido exclui o enquadramento do protocolo, a saída, os tokens em cache e os encargos da conta. A compactação desta amostra poupa 38 tokens de conteúdo de entrada. A amostra demonstra o efeito da serialização local. Qualidade das respostas, distribuição real de custos e desempenho da API ainda precisam ser aferidos com observações comparáveis da hospedagem.

## 5. Implantação e reversão

1. Mantenha os diretórios de memória e vetores atuais. Prepare, se desejar observar custo, um diretório privado para métricas. Configure as tarifas de acordo com os modelos utilizados e a data da comparação.
2. Faça um teste breve com conta sintética e confira a resposta, o streaming, a persistência e a leitura de uso por categoria. Para cache, compare diversos pedidos elegíveis e verifique o número de tokens em cache efetivamente informado.
3. Se a recuperação adaptativa, o cadastro local, a proteção contra reenvios ou o contexto do relatório degradarem algum fluxo, desative a otimização correspondente pelas variáveis acima. Remova orçamentos personalizados para voltar à configuração padrão. Remova a variável de métricas para desligar a coleta. As mudanças de montagem não exigem recriar o histórico ou os vetores SQLite.
4. Para reversão integral, reimplante a revisão anterior sem remover memória, vetores ou o diretório privado de medição. Antes de voltar, confirme que os backups e o acesso do PHP continuam íntegros. Nenhum dado real deve ser sobrescrito durante a avaliação.

Próximas evoluções avaliáveis após dados suficientes: comparar qualidade de recuperação desativada versus ativa em casos anonimizados, calibrar orçamentos por duração e assunto e medir frequência e benefício das reformulações. Uma nova arquitetura de armazenamento, um novo modelo ou um avaliador pago só se justificam com evidência comparável.
