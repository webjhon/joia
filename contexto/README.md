# Contexto de desenvolvimento da JOIA

Este diretório guarda pedidos e decisões sobre mudanças no projeto para consulta em tarefas futuras do Codex. A busca local recupera os registros relevantes para o agente ler antes de editar. O fluxo usa Python 3 e a biblioteca padrão.

```bash
python3 contexto/buscar.py "avatar WebGL voz"
python3 contexto/buscar.py "preservar escopo alterações" --limite 3
```

A busca ignora caixa e acentos. Ela compara palavras da consulta com títulos, termos, pedidos e decisões. Use os nomes dos arquivos ou componentes se a primeira consulta vier vazia. Os resultados mostram a situação, a fonte e o caminho do registro para leitura.

## Registrar um pedido

Crie um arquivo JSON em `registros/` com um nome como `2026-11-25-assunto.json`. Inclua a formulação do usuário quando for seguro preservá-la. Se precisar resumir ou omitir dados privados, identifique o texto como resumo. Separe decisões de implementação do pedido original e anote a fonte disponível, como um PR, um commit ou a conversa atual. Use um arquivo por assunto e mantenha os registros junto do trabalho para que cheguem aos próximos checkouts.

```json
{
  "titulo": "Assunto da tarefa",
  "situacao": "discussao",
  "fonte": "Conversa com o usuário ou referência ao PR",
  "termos": ["componente", "palavra-chave"],
  "pedidos": ["Pedido original ou resumo identificado"],
  "decisoes": ["Decisão e evidência de aprovação, quando houver"]
}
```

Use `aprovada` quando houver autorização explícita, `discussao` para propostas ainda em aberto, `substituida` para decisões superadas e `historico` para fatos reconstruídos do Git. Ao substituir uma decisão, cite o arquivo novo na decisão antiga. Um commit comprova que houve uma mudança no projeto. Para afirmar que o usuário a aprovou, use o registro dessa aprovação.

O acervo inicial usa esta conversa e o Git. Conversas antigas do Codex podem ser incorporadas se voltarem a ficar acessíveis. O agente consulta e atualiza os registros por meio do `AGENTS.md`. Para que outra tarefa veja os registros, o checkout dela precisa conter os arquivos atualizados.
