<div align="center">

<img src="assets/readme/joia-banner.svg" alt="Banner da JOIA, unindo educação e inteligência artificial" width="100%">

# 💎 JOIA

### Inteligência artificial a serviço de uma aprendizagem mais humana

**Educação · Programação · Acompanhamento · Autonomia**

</div>

---

## Sobre o projeto

A **JOIA** é uma assistente educacional criada para apoiar estudantes em suas
experiências com programação e inteligência artificial. Cada resposta considera
o histórico do próprio aluno para dar continuidade às suas dúvidas, tentativas
e projetos.

## Propósito

O projeto nasceu do desejo de tornar o uso da inteligência artificial em sala de
aula mais **consciente, orientado e significativo**. A JOIA procura ajudar o
estudante a desenvolver autonomia, ao mesmo tempo que oferece ao professor uma
visão mais clara de sua trajetória.

Seus princípios são:

- **responder à dúvida no contexto do estudante**, valorizando a construção do
  conhecimento;
- **respeitar o ritmo de cada estudante**, reconhecendo diferentes formas de
  aprender;
- **incentivar a curiosidade**, a experimentação e o pensamento crítico;
- **aproximar professor, aluno e tecnologia** sem perder o cuidado humano;
- **acompanhar a evolução**, fazendo de cada tentativa uma parte importante da
  aprendizagem.

## Uma experiência de acompanhamento

A JOIA mantém cada jornada de aprendizagem organizada individualmente. Ao longo
das conversas, ela reúne elementos que ajudam a compreender dúvidas, avanços e
pontos que ainda merecem atenção.

Esse acompanhamento permite que a inteligência artificial atue como apoio ao
estudo, enquanto o olhar do educador continua no centro das decisões
pedagógicas. Assim, tecnologia e docência trabalham juntas para oferecer uma
orientação mais intencional e próxima.

## Versionamento

O projeto utiliza **Git** para registrar sua história e preservar a evolução de
cada etapa. As versões públicas seguem uma numeração simples e progressiva,
facilitando a identificação de mudanças relevantes na experiência, nas
orientações pedagógicas e nos recursos de acompanhamento.

> **Versão atual: 1.22 · Marco zero das respostas**

Cada nova versão representa um ciclo de aperfeiçoamento. Mais do que adicionar
funcionalidades, o objetivo é refinar a qualidade das interações, a segurança
dos fluxos e a coerência da proposta educacional.

## Desenvolvedor

<div align="center">

<a href="https://www.linkedin.com/in/joaocarlosms/">
  <img src="assets/readme/joao-carlos.svg" alt="Foto de João Carlos de Moura dos Santos" width="140">
</a>

### João Carlos de Moura dos Santos

[Conheça seu perfil no LinkedIn](https://www.linkedin.com/in/joaocarlosms/)

</div>

Professor do **SENAC de Ijuí**, João Carlos de Moura dos Santos desenvolveu a
JOIA a partir de seu interesse em acompanhar de maneira mais orientada os alunos
em suas aventuras com a inteligência artificial.

Sua iniciativa parte da convicção de que a IA pode ampliar possibilidades na
educação quando utilizada com propósito, mediação e responsabilidade. A JOIA é,
portanto, um encontro entre prática docente, tecnologia e o compromisso de
ajudar estudantes a aprender com mais confiança e autonomia.

---

<div align="center">

**JOIA** — aprender com tecnologia, crescer com orientação.

</div>

## Manual técnico

O [manual técnico da JOIA](documentacao/index.html) explica o uso do chat,
a arquitetura, as estratégias de recuperação e a operação.

## Memória vetorial dos alunos

O chat consulta a memória do aluno autenticado a cada resposta. A janela padrão
inclui até 12 mensagens recentes. Quando a busca vetorial encontra
correspondências, o chat envia até duas trocas anteriores relevantes, as quatro
mensagens mais recentes e a pergunta atual. O histórico completo continua no
JSON privado do aluno e segue disponível para os relatórios.

Para ativar, habilite as extensões PHP `pdo_sqlite`, `curl` e `mbstring` e
configure `JOIA_VECTOR_DIR` com o caminho de um diretório privado, gravável
pelo PHP, fora da raiz pública e do repositório. O PHP cria
`joia-vetores.sqlite3` nesse diretório. Use uma pasta com acesso restrito à
conta do serviço. A configuração `OPENAI_API_KEY` também é usada para
`text-embedding-3-small`. O banco armazena somente o identificador interno,
a versão do modelo, o hash da pergunta e o vetor. A API de embeddings recebe
o texto das perguntas, que pode conter dados digitados pelo aluno.

A JOIA indexa perguntas anteriores em pequenos lotes durante o chat e
reaproveita os vetores nas próximas buscas. A comparação por cosseno é local
e percorre apenas os vetores do aluno. Se a busca for dispensada, não encontrar resultados ou o serviço estiver
indisponível, o chat usa a janela padrão de 12 mensagens. O armazenamento dos vetores é derivado e pode ser recriado a
partir do histórico. A busca usa o cadastro já associado à sessão do chat.

## Código de acesso e caderno

No primeiro cadastro, guarde o código exibido pela JOIA. Em outro dispositivo,
use **Já tenho um código** para abrir um chat limpo com o mesmo contexto
pedagógico e recuperar seu caderno. As conversas antigas ficam no arquivo
privado e podem orientar respostas futuras. Guarde o código em segredo. Em
computadores compartilhados, encerre o acesso ao terminar.

A memória dos alunos fica em `joia-memoria`, ao lado da raiz pública do servidor.
Para usar outro diretório privado e gravável pelo PHP, configure
`JOIA_MEMORY_DIR`. A hospedagem deve usar HTTPS. Na atualização, mova os arquivos
legados `memoria/aluno_*.json` para o diretório privado, preservando os nomes.
O Apache 2.4 também recebe uma regra que bloqueia o acesso HTTP à pasta legada.
Em outros servidores, remova os arquivos legados da raiz pública ao migrar;
o servidor embutido do PHP ignora regras `.htaccess`.

Um cadastro antigo ainda associado à sessão recebe um código ao abrir a JOIA.
Registros antigos sem uma sessão associada precisam de um processo de
recuperação que confirme a identidade do aluno. O caderno da versão anterior
pode ser baixado na tela de entrada do mesmo navegador e copiado para o caderno
do aluno após o acesso.

## Respostas progressivas

No chat do aluno, a pergunta permanece visível durante o envio. O PHP confirma
o recebimento antes de preparar o contexto e encaminha os trechos enviados
pela OpenAI em eventos NDJSON. O navegador monta a resposta progressivamente e
formata o Markdown. O histórico, as anotações e a leitura em voz alta usam o
texto recebido. Se a geração terminar antes da conclusão, o chat mantém o
texto disponível e sinaliza a interrupção.
Clientes que pedem JSON recebem o texto em uma única resposta.

Para confirmar o streaming em uma hospedagem, verifique se o navegador recebe
eventos progressivamente na requisição a `teste_openai.php`. Se todos os eventos
chegarem juntos, verifique o buffering de saída do PHP e da camada de proxy ou
compressão da hospedagem. A busca de contexto ocorre antes de o modelo começar
a gerar texto.

## Código nas respostas

No marco zero, a JOIA usa um treinamento breve focado na continuidade pelo
histórico. O chat encaminha a resposta do modelo, incluindo blocos completos,
arquivos de exemplo e soluções em qualquer linguagem. A aplicação não aplica
cota de código, restrição de assunto, limite de linhas ou teto local de tokens
ao chat. O navegador apresenta as caixas de código em Markdown e usa destaque
de sintaxe nas linguagens reconhecidas.

## Consumo da API

[A auditoria técnica e o guia de operação](documentacao/otimizacao-api.md) descrevem as métricas privadas, as opções de configuração e a comparação local.

## Referências da conversa e materiais do professor

Quando a dúvida aponta para um trecho de código já mostrado, a JOIA pode
incluí-lo no contexto do modelo. O [acervo técnico local](documentacao/inteligencia-artificial.html#contexto)
acrescenta referências pertinentes à conversa recente, aos materiais do
professor e à memória individual do aluno.

Os materiais do professor usam um catálogo local em
`includes/materiais_didaticos.php`. Perguntas sobre onde encontrar uma aula
ajudam a selecionar o título, a orientação de localização e o objetivo para
estudo. Esses dados integram o contexto que o modelo recebe, e o chat mostra os
títulos associados em texto simples.
