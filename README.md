# JOIA

**Versão atual: 1.6**

Assistente educacional de programação que usa a API da OpenAI e segue as
orientações pedagógicas de `treinamento.txt`.

## Configuração na Hostinger

1. Revogue qualquer chave que já tenha sido publicada no código ou no histórico
   do Git e crie uma nova chave no painel da OpenAI.
2. No seu computador, faça uma cópia de `config.local.example.php` com o nome
   exato `config.local.php` e preencha a chave somente nessa cópia:

   ```php
   <?php
   declare(strict_types=1);

   return [
       'OPENAI_API_KEY' => 'sua-chave-real',
   ];
   ```

3. No hPanel da Hostinger, abra **Sites > Gerenciar > Gerenciador de Arquivos**.
   Acesse a pasta que contém `public_html` e envie ali o `config.local.php` já
   preenchido, **fora de `public_html`**. Não é necessário editar `.env` nem
   `.htaccess` pelo painel. Se disponível, defina a permissão do arquivo como
   `600` (ou `640` se `600` impedir a leitura pelo PHP).
4. Confirme que `config.local.php` está exatamente dois níveis acima da pasta
   `joia`. Por exemplo, para
   `/home/usuario/domains/site/public_html/joia/teste_openai.php`, o arquivo deve
   estar em `/home/usuario/domains/site/config.local.php`. O código resolve esse
   local com `dirname(__DIR__, 2)`, sem caminho absoluto nem nome de usuário fixo.
   Nunca envie o arquivo real ao GitHub nem compartilhe seu conteúdo.
5. Garanta que a extensão PHP cURL esteja habilitada e que o usuário do servidor
   possa escrever no diretório `memoria/`.
   O backend evita recursos exclusivos do PHP 8 para continuar compatível com
   instalações Hostinger que ainda executam PHP 7.4.
6. Acesse a aplicação. Para desenvolvimento local, execute
   `php -S localhost:8000` e abra `http://localhost:8000`.

O backend tenta primeiro `getenv('OPENAI_API_KEY')`, caso a hospedagem já forneça
a variável. Somente quando ela não existe ou está vazia, carrega
`config.local.php` dois níveis acima da pasta do projeto. Se o arquivo estiver ausente,
inválido ou com a chave vazia,
a API retorna um erro tratado sem expor a chave. A chave permanece exclusivamente
no PHP do servidor: nunca a coloque no HTML, no JavaScript, em arquivos
versionados, logs ou mensagens de erro.

## Funcionamento

- O modelo interrompe a primeira dúvida para explicar e conduzir um cadastro natural
  com nome, turma/curso e instituição, evitando confundir alunos em computadores
  compartilhados. Cada aba recebe uma conversa isolada e há uma ação de novo cadastro.
- Cada aluno recebe um identificador aleatório independente de nome ou turma; os dados
  humanos e o histórico ficam em um registro versionado dentro de `memoria/`.
- Em conversas comuns, apenas as 12 mensagens mais recentes são enviadas à API.
- O comando docente `iProgram.ger` exige token, lista apenas dados humanos dos alunos
  e gera, sob demanda, diagnóstico pedagógico e relatório PDF A4 diagramado, com
  seções, parágrafos, listas, tipografia e caracteres acentuados para download.
- Os conteúdos de `treinamento.txt` e `NEGATIVEPROMPTS.TXT` são carregados em toda
  chamada ao modelo. O segundo documento registra comportamentos que não podem
  ocorrer, especialmente repetições, regressões de cadastro e exigências de formato.
