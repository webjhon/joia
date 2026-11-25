#!/usr/bin/env python3
"""Recupera registros de desenvolvimento relevantes para uma consulta."""

import argparse
import json
from pathlib import Path
import re
import unicodedata


REGISTROS = Path(__file__).resolve().parent / "registros"
PALAVRAS_COMUNS = set(
    "a as ao aos com como da das de do dos e ela ele em essa esse esta este "
    "eu foi mas me meu minha na nas no nos o os ou para pela pelo por pode "
    "preciso que se sem ser sua suas um uma voce".split()
)


def palavras(texto):
    normalizado = unicodedata.normalize("NFKD", texto.casefold())
    sem_acentos = "".join(
        caractere for caractere in normalizado if not unicodedata.combining(caractere)
    )
    return set(re.findall(r"[^\W_]+", sem_acentos)) - PALAVRAS_COMUNS


def buscar(consulta):
    resultados = []
    for caminho in sorted(REGISTROS.glob("*.json")):
        try:
            registro = json.loads(caminho.read_text(encoding="utf-8"))
            assunto = palavras(" ".join([registro["titulo"], *registro["termos"]]))
            conteudo = palavras(" ".join(registro["pedidos"] + registro["decisoes"]))
            pontuacao = 3 * len(consulta & assunto) + len(consulta & conteudo)
            resultado = (pontuacao, caminho, registro["titulo"], registro["situacao"], registro["fonte"])
        except (OSError, ValueError, KeyError, TypeError) as erro:
            raise SystemExit(f"Registro inválido em {caminho.name}. {erro}") from erro
        if pontuacao:
            resultados.append(resultado)
    return sorted(resultados, key=lambda resultado: (-resultado[0], resultado[1].name))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("consulta", help="Pedido atual ou termos dos componentes afetados")
    parser.add_argument("--limite", type=int, default=5, help="Máximo de registros exibidos")
    argumentos = parser.parse_args()
    if argumentos.limite < 1:
        parser.error("--limite deve ser maior que zero")
    consulta = palavras(argumentos.consulta)
    if not consulta:
        parser.error("informe ao menos um termo relevante")

    resultados = buscar(consulta)
    if not resultados:
        print("Nenhum registro correspondente.")
    for _, caminho, titulo, situacao, fonte in resultados[:argumentos.limite]:
        print(f"[{situacao}] {titulo}")
        print(f"  Fonte — {fonte}")
        print(f"  Registro — contexto/registros/{caminho.name}")


if __name__ == "__main__":
    main()
