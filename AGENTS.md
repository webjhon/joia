# Desenvolvimento da JOIA

- O pedido atual define o escopo. Preserve os comportamentos já aprovados nas partes que o pedido não abrange. Peça autorização antes de ampliar o escopo quando a mudança depender de alterar outro comportamento.
- Antes de editar, leia `contexto/README.md` e rode `python3 contexto/buscar.py "termos do pedido e componentes afetados"` a partir da raiz. Abra os registros encontrados e confira a situação, a fonte e o código atual. Use o histórico como referência. As instruções atuais do usuário prevalecem sobre registros anteriores.
- Em revisões pedagógicas e técnicas, use o caso relatado para reproduzir a falha e rastrear sua causa no fluxo comum. Identifique quais situações podem falhar pelo mesmo motivo, corrija o comportamento compartilhado e valide tanto a ocorrência original quanto cenários equivalentes com outros assuntos ou formulações. Declare a cobertura parcial quando o trabalho resolver apenas parte do problema.
- Antes de concluir, confira o diff contra o pedido e verifique os comportamentos afetados. Registre em `contexto/registros/` os pedidos e decisões de desenvolvimento relevantes desta tarefa, inclusive correções e autorizações de escopo, seguindo o formato documentado. Atualize a situação de uma decisão anterior quando ela for explicitamente substituída e vincule o novo registro.
- Retire credenciais, dados de alunos e outros dados privados dos registros. Inspecione o conteúdo antes de incluí-lo no Git.

- Entregue toda mudança no repositório em um pull request para a branch de destino. Trabalhe em uma branch separada, rode as verificações pertinentes e inclua o link do PR na resposta. Deixe o merge para o usuário, salvo autorização explícita.
