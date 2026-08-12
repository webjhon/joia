# JOIA

Assistente educacional de programação que usa a API da OpenAI e segue as
orientações pedagógicas de `treinamento.txt`.

## Configuração

1. Revogue qualquer chave que já tenha sido publicada no código ou no histórico
   do Git e crie uma nova chave no painel da OpenAI.
2. Disponibilize a chave **somente no servidor**. Em hospedagem compartilhada
   Hostinger, crie pelo Gerenciador de Arquivos um arquivo chamado `.env`, de
   preferência um nível acima de `public_html`, com o seguinte conteúdo:

   ```dotenv
   OPENAI_API_KEY=sua-chave-real
   ```

   Se a aplicação estiver instalada diretamente em `public_html` e não for
   possível criar o arquivo no nível acima, coloque `.env` na raiz da aplicação.
   O `.htaccess` incluído bloqueia o acesso HTTP a esse arquivo no
   Apache/LiteSpeed. Use permissões `600` quando o painel permitir. O `.env` é
   ignorado pelo Git; `.env.example` contém somente o formato esperado.

3. Garanta que a extensão PHP cURL esteja habilitada e que o usuário do servidor
   possa escrever no diretório `memoria/`.
4. Sirva o diretório com PHP (para desenvolvimento, `php -S localhost:8000`) e
   abra `http://localhost:8000`.

O carregador preserva uma `OPENAI_API_KEY` definida nativamente pelo servidor e
usa o `.env` apenas como fallback. Nunca coloque a chave no HTML, em arquivos
versionados ou em mensagens de erro.

## Funcionamento

- Nome, tecnologia e turma são coletados sem chamada à API, economizando tokens.
- O histórico persistente é identificado por hash do nome do aluno.
- Em conversas comuns, apenas as 12 mensagens mais recentes são enviadas à API.
- O histórico completo é usado apenas no comando especial `iProgram.ger`.
- O conteúdo de `treinamento.txt` é carregado como instrução de sistema em cada
  solicitação, mantendo as regras pedagógicas em um único lugar.
