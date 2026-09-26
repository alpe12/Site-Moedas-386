# Site-Moedas-386 — versão PHP + CSV local

Esta versão remove as chamadas aos Google Apps Script e usa sessão PHP + CSVs
locais em `.private/`.

**Este arquivo vive dentro de `.private/` de propósito** — ele descreve
detalhes de validação, limites e nomes de campos internos que não deveriam
ficar acessíveis publicamente (`.private/.htaccess` já bloqueia todo o
diretório). Se você mover este README para fora de `.private/`, ele passa a
ser baixável direto pela URL por qualquer visitante.

## ⚠️ Antes de publicar: cheque as permissões de `.private/`

O servidor precisa conseguir **escrever** dentro de `.private/` (não só ler)
— isso inclui a subpasta `.private/sessoes/`, usada para guardar as sessões
de login (veja "Sessões" mais abaixo). Se uma compra falhar sempre com "Não
foi possível debitar o saldo" (ou qualquer ação com "sistema
ocupado"/"não foi possível acessar os dados"), quase sempre é isso:

1. Confira se a pasta `.private/` (com o ponto na frente) realmente foi
   enviada ao servidor — **muitos clientes de FTP e painéis de hospedagem
   escondem/ignoram pastas que começam com ponto por padrão**, então é fácil
   ela nunca ser enviada.
2. Confira se o usuário do PHP (geralmente `www-data` ou similar) tem
   permissão de escrita em `.private/` e em cada CSV dentro dela
   (`chmod 664` nos arquivos costuma bastar; `chmod 775` na pasta e em
   `.private/sessoes/`).
3. Veja o log de erros do PHP — as funções de trava agora escrevem uma linha
   em `error_log()` sempre que não conseguem abrir ou travar um arquivo,
   dizendo exatamente qual arquivo e por quê.

## Configuração central

`api/config.php` é o único lugar para ajustar as regras abaixo — tanto o PHP
quanto o navegador (via `api/config_publica.php`) leem daqui, então nunca
ficam dessincronizados:

- **Regras de senha**: tamanho mínimo, mínimo de letras/números/caracteres
  especiais (todos 0 = sem exigência, exceto o tamanho mínimo que é 8 por
  padrão).
- **Formato da matrícula**: tamanho fixo (padrão 15, como
  `202518001323310`) e a faixa de anos aceita no prefixo. A mensagem de erro
  mostrada ao usuário é sempre só "Matrícula inválida." de propósito — não
  entra em detalhe de qual regra falhou, para não dar dica de como formar
  uma matrícula falsa que passe na validação.
- **Formato da turma**: tamanho fixo (padrão 4 dígitos).
- **`WHATSAPP_LINK`**: o link usado em todo o site sempre que se sugere
  "fale com um monitor" — troque `SEUNUMERO` pelo número real.
- **`EXIGIR_APROVACAO_CONTA`** (padrão `false`): toda conta nova sempre
  nasce com `ativo=0` em `usuarios.csv`, **independente desta config** —
  assim dá pra saber quais foram confirmadas manualmente. Esta config só
  decide se esse campo é *exigido*: com `true`, contas com `ativo=0` ficam
  de fora do ranking/loja até alguém trocar para `1` no CSV; com `false`
  (padrão) o valor é ignorado e toda conta se comporta como aprovada — igual
  ao comportamento anterior.
- **`MOSTRAR_APENAS_PRIMEIRA_LETRA`** (padrão `false`): telas públicas
  mostram só o primeiro nome do aluno (`Douglas`). Se `true`, mostram só a
  primeira letra (`D******`) e a busca da página de ranking some (tanto na
  tela quanto na API).
- **`LOJA_VISIVEL_SEM_LOGIN`** (padrão `false`): se `true`, visitantes sem
  login também veem o catálogo da loja (sem saldo, sem poder comprar).
- **`LOCK_TIMEOUT_SEGUNDOS`** (padrão 10): quanto tempo uma escrita espera
  por um arquivo CSV ocupado antes de desistir (ver seção de concorrência).
- **`RANKING_LIMITE_PADRAO` / `RANKING_LIMITE_MAXIMO`**: quantos alunos a
  página de ranking mostra por padrão, e o teto que ninguém pode passar —
  mesmo pedindo um `limite` maior na URL, o servidor sempre corta em
  `RANKING_LIMITE_MAXIMO`. O seletor "Mostrar" na página se adapta sozinho a
  esse teto (nunca oferece mais do que ele, e sempre oferece o teto como a
  maior opção).
- **`RANKING_BUSCA_LIMITADA`** (padrão `true`) e **`RANKING_BUSCA_LIMITE_N`**:
  com o padrão ligado, a busca da página de ranking só encontra alunos
  dentro do topo `max(RANKING_BUSCA_LIMITE_N, RANKING_LIMITE_MAXIMO)` por
  saldo — ou seja, ninguém consegue descobrir, testando termo por termo, um
  aluno que não conseguiria ver de nenhuma outra forma na página. Desligar
  volta ao alcance completo (busca alcança qualquer aluno com saldo > 0).
- **`RANKING_BUSCA_LOCAL`** (padrão `true`): só tem efeito quando
  `RANKING_BUSCA_LIMITADA` também está ligado (o padrão dos dois juntos).
  Nesse caso, o navegador já carrega de uma vez, ao abrir a página,
  exatamente o mesmo conjunto de alunos que uma busca no servidor
  enxergaria — então filtrar essa lista já carregada dá o mesmo resultado
  de uma busca no servidor, mas sem gastar uma requisição a cada letra
  digitada. Não expõe mais dado nenhum: o alcance continua sendo o mesmo
  `max(RANKING_BUSCA_LIMITE_N, RANKING_LIMITE_MAXIMO)`, só muda se esses
  alunos chegam ao navegador de uma vez (aqui) ou aos poucos, conforme a
  busca (desligando esta config). Sem `RANKING_BUSCA_LIMITADA` ligado, esta
  config é ignorada — buscar localmente exigiria carregar a lista inteira
  de alunos no navegador, o que anularia o propósito de não ter um alcance
  ilimitado.
- **`LOGIN_DURACAO_ATIVADA`** (padrão `true`) e **`LOGIN_DURACAO_SEGUNDOS`**
  (padrão 8 horas): depois desse tempo sem nenhuma requisição autenticada, a
  sessão expira sozinha (veja "Duração do login" mais abaixo). Desligado,
  volta ao comportamento antigo (sessão dura até o navegador fechar).
- **`EXIGIR_APROVACAO_TROCA_TURMA`** (padrão `false`, em
  `config_compartilhada.php` — compartilhada com o painel admin): mesma
  lógica de `EXIGIR_APROVACAO_CONTA`, mas para trocas de turma pedidas pelo
  aluno. Veja "Troca de turma" mais abaixo.
- **`EXIGIR_APROVACAO_TROCA_SERIE_REPETIDA`** e **`TROCA_TURMA_UMA_LIVRE_POR_ANO`**
  (ambas padrão `true`): forçam aprovação em duas situações específicas da
  primeira troca do ano, mesmo com `EXIGIR_APROVACAO_TROCA_TURMA` desligada.
  Veja "Forçando aprovação em casos específicos" mais abaixo.
- **`RANKING_APENAS_ANO_ATUAL`** (padrão `true`) e **`EXPIRAR_SALDO_ANO_NOVO`**
  (padrão `false`): duas configs independentes sobre o que "conta" a cada
  ano letivo novo — uma só pro ranking, outra pro saldo de verdade. Veja
  "Ranking: apenas o ano atual, e expiração de saldo" mais abaixo.

## Dados locais

- `.private/usuarios.csv` — contas, e-mails, hash de senha, token de
  recuperação e status de aprovação.
- `.private/atividades.csv` — créditos/ajustes de saldo por aluno.
- `.private/itens_loja.csv` — catálogo de itens da loja.
- `.private/pedidos_loja.csv` — pedidos da loja (compras e cancelamentos).
- `.private/rate_limit.json` — controle interno de tentativas de login/cadastro (gerado automaticamente, pode ser apagado a qualquer momento).

**Não existe mais um arquivo com o saldo "pronto" de cada aluno.**
`ganho`, `gasto` e `saldo` são sempre calculados na hora, a partir de
`atividades.csv` + `pedidos_loja.csv` (veja `calcular_resumo_financeiro()`
em `api/_bootstrap.php`). Isso evita que o saldo mostrado e o histórico que
o explica fiquem dessincronizados por causa de uma edição manual em só um
dos dois lados.

### Formatos

`usuarios.csv`

    matricula,nome,email,senha_hash,reset_token,ativo

- `reset_token`: 6 caracteres A-Z0-9, gerado no cadastro e mostrado ao aluno
  **uma única vez**, na hora. Fica salvo em texto puro de propósito — é para
  um monitor conseguir abrir o CSV e ler o código para ajudar o aluno, não é
  um segredo criptográfico.
- `ativo`: toda conta nova sempre nasce com `0`. Só passa a ser levado em
  conta (bloqueando ranking/loja) quando `EXIGIR_APROVACAO_CONTA` está
  ligado em `config.php` — veja a seção de configuração acima.
- Não existe uma coluna `turma` aqui — desde que a troca de turma pelo
  aluno foi adicionada, a turma (atual e todo o histórico de trocas) vive
  inteiramente em `turmas_historico.csv`. Veja a seção "Troca de turma"
  abaixo.

`atividades.csv`

    id,matriculas,data,atividade,valor

`id` identifica a linha — usado pelo painel admin (`/admin/atividades.html`)
para editar ou remover uma atividade específica sem ambiguidade. Cada
linha é um crédito ou ajuste de saldo — `valor` pode ser negativo (por
exemplo, para descontar por alguma penalidade). A soma de `valor` de um
aluno é o "ganho" dele. `matriculas` aceita mais de uma matrícula na mesma
linha (separadas por `;`) — veja "Atividades para vários alunos de
uma vez" mais abaixo.

`carrossel.csv`, `eventos.csv`, `projetos.csv`

Conteúdo editorial da página inicial (fotos do carrossel, eventos em
destaque, projetos da escola), editável em `/admin/conteudo.html` e
servido ao site público via `api/conteudo_publica.php`. Cada um tem `id` e
`ordem` (menor primeiro na exibição) além dos campos próprios de texto
(`imagem`/`legenda`; `tag`/`titulo`/`descricao`/`rodape`/`link`;
`titulo`/`parceria`/`descricao`, respectivamente).

`itens_loja.csv`

    id,nome,valor,icone,imagem,ativo

- `id` é um identificador curto e estável (ex.: `caderno`, `vale-cinema`).
  Pedidos guardam esse id, então **não reaproveite um id para um item
  diferente** depois que ele já tiver pedidos associados.
- `icone`: um emoji ou texto curto mostrado no cartão do item.
- `imagem`: URL de uma foto (png/jpg/etc). Se preenchida, tem prioridade
  sobre `icone`. Deixe em branco para usar só o ícone.
- `ativo` é `1` (aparece na loja) ou `0` (some da loja, mas continua no
  arquivo). Para "remover" um item, edite a linha e troque `ativo` para `0`
  — não apague a linha, pois pedidos antigos continuam referenciando esse id.
- Para adicionar um item novo, basta acrescentar uma linha com um id novo.
  Nenhum código precisa mudar — nem HTML, já que o grid da loja é montado
  100% a partir deste arquivo (não existe conceito de "categoria").

`pedidos_loja.csv`

    id,data,matricula,nomeAluno,item_id,item,valor,status

`item_id` referencia `itens_loja.csv`. `item` e `valor` são uma cópia do nome
e do preço no momento da compra, para que o histórico de um pedido não mude
se o item for renomeado ou tiver o preço ajustado depois.

`status` pode ser `Pendente`, `Aprovado`, `Resgatado` ou `Cancelado`.
**Só pedidos `Cancelado` ficam de fora da conta de gasto** — cancelar um
pedido "devolve" o valor automaticamente por isso, sem nenhuma transação de
estorno separada (veja "Cancelamento de pedidos" abaixo). O aluno só pode
cancelar pedidos que ainda estão `Pendente`.

`turmas_historico.csv`

    id,matricula,turma,ano,data_solicitacao,data_efetiva,retroativo_definido,aprovacao_forcada,aprovado

Uma linha por associação aluno/turma/ano — **nunca edita ou apaga uma
linha antiga** (a única exceção é um cancelamento pelo próprio aluno de
uma solicitação ainda não decidida, que remove a linha — veja "Troca de
turma" mais abaixo), só acrescenta. É assim que o aluno mantém o histórico
completo de todas as turmas por que já passou, e é o que permite mostrar
(no painel admin) a turma que ele tinha exatamente na época de um pedido
ou atividade antiga, mesmo que já tenha trocado de turma depois.

`id` é só um identificador opaco da linha (tipo `turma-a1b2c3d4`) — **não**
é composto de turma+ano: turma+ano não é único por linha (uma turma
inteira de alunos compartilha o mesmo par, e um aluno pode até ter duas
linhas pro mesmo par depois de uma solicitação recusada seguida de uma
nova). `turma` e `ano` ficam em colunas próprias de propósito, não por
não poderem ser combinados num id — é que praticamente toda função deste
arquivo lê turma e ano isoladamente, e reconstruir os dois a partir de um
id toda vez custaria mais código do que duas colunas simples custam espaço.

Veja a seção "Troca de turma" mais abaixo para o funcionamento completo
(inclusive o que `data_efetiva`/`retroativo_definido` significam).

## Senhas

Use `password_hash()` para preencher `senha_hash` de qualquer conta criada
manualmente. Todas as regras de validação (tamanho, letras, números,
caracteres especiais) vêm de `api/config.php` — veja a seção acima.

Não existe suporte a senha legada em texto puro: como todas as contas
partem do zero nesta versão, esse caminho de migração nunca foi necessário.

## Segurança

A matrícula não é usada como mecanismo de autenticação no navegador.

Depois do login:

- o PHP cria uma sessão;
- o cookie é `HttpOnly`, `SameSite=Lax` e `Secure` quando servido por HTTPS;
- o JS não usa `sessionStorage` para autenticação;
- APIs protegidas obtêm a matrícula exclusivamente de `$_SESSION`.

No servidor:

- o preço de cada item é sempre lido do catálogo (`itens_loja.csv`), nunca do
  que o navegador envia;
- comprar e cancelar são operações atômicas sob um único lock em
  `pedidos_loja.csv` (calcula o saldo, confere e já grava a mudança numa
  única operação), para que ações simultâneas do mesmo aluno não consigam
  gastar o mesmo saldo duas vezes nem cancelar/reaproveitar o mesmo pedido
  duas vezes (veja "Escritas concorrentes" abaixo);
- o histórico de pedidos retornado ao aluno contém apenas os pedidos dele, e
  nunca a matrícula/e-mail de outros alunos;
- `login` e `redefinirSenha` têm limite de tentativas por IP+alvo (arquivo
  `.private/rate_limit.json`), para dificultar força bruta. **`cadastro` não
  é limitado por IP** — várias contas legítimas de alunos costumam vir do
  mesmo IP de saída numa escola, então isso usa um limite global
  (compartilhado por todo mundo) só para conter abuso automatizado de
  verdade, sem arriscar bloquear uma turma inteira se cadastrando ao mesmo
  tempo;
- nome e turma digitados no cadastro passam por uma validação de caracteres
  antes de ir para o CSV — isso evita que um cadastro malicioso injete HTML/JS
  em quem visualiza o ranking, e evita que um valor comece com `=`, `+`, `-`
  ou `@` (o que poderia disparar uma fórmula caso o CSV seja aberto no Excel);
- ao digitar nos campos de cadastro, o navegador já descarta na hora
  qualquer caractere fora do permitido (só números em matrícula/turma, só
  letras em nome), avisando com um toast — isso é só conveniência de UX, a
  validação de verdade continua sendo feita pelo servidor;
- **o nome do aluno já sai recortado do servidor** em qualquer endpoint
  público (`api/ranking.php`) — primeiro nome, ou primeira letra conforme
  `MOSTRAR_APENAS_PRIMEIRA_LETRA`. Isso é feito no PHP e não no navegador de
  propósito: se o nome completo saísse na resposta da API, qualquer pessoa
  poderia vê-lo pela aba de rede do navegador mesmo que a tela mostrasse só
  uma parte;
- alunos/turmas com saldo (ou gasto) zero nunca aparecem em nenhuma tela
  pública de ranking;
- cadastro rejeita matrícula, e-mail **e nome completo** já cadastrados
  (com uma mensagem indicando para falar com um monitor caso seja engano —
  duas pessoas com o mesmo nome, por exemplo).

### Cancelamento de pedidos

Na tela "Meus Pedidos Realizados", pedidos com status `Pendente` mostram um
botão "Cancelar". Cancelar só muda o `status` para `Cancelado` — não existe
uma transação de estorno separada, porque pedidos `Cancelado` simplesmente
param de entrar na soma de gasto (veja `calcular_resumo_financeiro()`), o
que já devolve o valor automaticamente. Isso também é o que impede devolver
o valor duas vezes: uma segunda tentativa de cancelar o mesmo pedido encontra
o status já diferente de `Pendente` e é recusada.

### Recuperação de senha

Cada conta recebe um código de 6 caracteres (`reset_token`) no momento do
cadastro, mostrado uma única vez em um card na tela — depois disso o site
nunca mais exibe esse código. `redefinirSenha` exige matrícula + e-mail +
esse código, os três batendo com o que está no CSV.

Se o aluno perder o código, a página de redefinição mostra um link para
falar com um monitor pelo WhatsApp (`WHATSAPP_LINK` em `config.php`); o
monitor pode abrir `usuarios.csv` e ler o `reset_token` em texto puro para
confirmar a identidade do aluno e ajudar manualmente.

`.private/.htaccess` bloqueia o diretório em Apache. Em Nginx, adicione também
uma regra para negar `/.private/`, pois `.htaccess` não é processado pelo Nginx.

### Escritas concorrentes (duas pessoas mexendo no mesmo CSV ao mesmo tempo)

Toda escrita (compra, cancelamento, cadastro, troca de senha) usa `flock()`
para travar o arquivo inteiro durante a operação. Se uma segunda requisição
chega enquanto o arquivo já está travado, ela **não falha na hora** — espera
e tenta de novo (com um pequeno intervalo crescente) até conseguir o lock, e
só desiste depois de `LOCK_TIMEOUT_SEGUNDOS` (10s por padrão) sem conseguir,
devolvendo uma mensagem clara de "sistema ocupado, tente novamente" em vez de
travar a página do aluno por tempo indefinido.

### Um CSV existente "cresce" sozinho quando o código ganha uma coluna nova

Quando uma atualização de código adiciona uma coluna nova a algum CSV (por
exemplo, `data_efetiva` em `turmas_historico.csv`), os arquivos que já
existem em produção **não têm** essa coluna ainda — só os arquivos novos,
criados do zero, nascem com o cabeçalho atual. `append_csv()` e
`with_locked_csv()` resolvem isso sozinhos, na hora: antes de ler ou
escrever, comparam o cabeçalho que já está no arquivo com o cabeçalho que o
código espera (`$fallbackHeaders`/`headers_para_arquivo()`) e, se faltar
alguma coluna, reescrevem o arquivo com o cabeçalho novo, preenchendo ""
nas colunas que as linhas antigas não tinham — remapeando cada linha **por
nome de coluna**, nunca por posição (importante: `aprovado`, por exemplo,
sempre fica como a última coluna de propósito, pra facilitar edição manual
— toda coluna nova é inserida ANTES dela, nunca depois; um remapeamento
por posição juntaria o valor antigo de "aprovado" com o nome de coluna
errado). Isso acontece sozinho na primeira escrita depois de atualizar o
código — nenhuma migração manual é necessária.

## Aprovação manual de contas

Com `EXIGIR_APROVACAO_CONTA = true` em `config.php`:

- toda conta (sempre nasce com `ativo=0` em `usuarios.csv`, ligado ou não)
  passa a ficar mesmo restrita enquanto não for aprovada;
- o aluno consegue logar e ver o próprio perfil normalmente (saldo,
  atividades), mas com um aviso de que a conta está pendente;
- `api/ranking.php` exclui essas contas de qualquer lista/pódio/soma por
  turma;
- `api/loja.php` recusa resgates dessas contas com uma mensagem clara;
- para aprovar, edite `usuarios.csv` e troque o `0` da coluna `ativo` para
  `1` na linha do aluno.

## Troca de turma

A turma repete de número todo ano (ex.: "0901" de 2025 não é a mesma turma
que "0901" de 2026), e um aluno pode trocar de turma no meio do ano — por
isso a turma nunca é só um valor guardado direto em `usuarios.csv`: ela vive
em `turmas_historico.csv`, uma linha por troca, e a "turma atual" de um
aluno é sempre a linha mais recente (por `data_efetiva`, não
`data_solicitacao` — veja "Primeira turma do ano conta retroativa" abaixo)
entre as que já valem. Isso preserva o histórico completo de turmas de
cada aluno.

O aluno solicita a troca em `perfil.html` (confirmando antes de enviar);
o servidor grava uma linha nova em `turmas_historico.csv` com `aprovado=0`
— **sempre**, mesmo com a aprovação manual desligada, funciona igual à
`ativo` de `usuarios.csv`:

- toda solicitação nasce com `aprovado=0` em `turmas_historico.csv`,
  ligada ou não a aprovação manual — isso deixa registrado, pra quem olhar
  o arquivo depois, que aquela troca não passou por conferência humana,
  mesmo que já esteja em vigor;
- **enquanto ainda estiver com `aprovado=0` E ainda represada (esperando
  um admin de verdade), o próprio aluno pode cancelar a solicitação**
  (link "Cancelar solicitação" ao lado do aviso em `perfil.html`) —
  diferente de uma recusa por um admin, cancelar **remove a linha
  inteira** de `turmas_historico.csv` em vez de marcar `aprovado=-1`: é
  como se a troca nunca tivesse sido pedida, não há decisão nenhuma pra
  manter registro de. Uma linha `aprovado=0` que já entrou em vigor (config
  desligada, sem `aprovacao_forcada`) não conta como pendente pra
  `perfil.html` — não aparece o aviso nem o link, porque não há nada
  esperando o aluno decidir (`api/profile.php` descarta essas antes de
  devolver `trocaTurmaPendente`); a ação `cancelar` do servidor
  (`api/turma.php`) continua aceitando qualquer linha `aprovado=0`, é só a
  interface do aluno que passou a restringir quando oferece o link. Depois
  de aprovada ou recusada, só um admin pode mexer;
- com `EXIGIR_APROVACAO_TROCA_TURMA = false` (padrão) em `config.php`, a
  linha já vale como a turma atual do aluno imediatamente, apesar do
  `aprovado=0`;
- com `EXIGIR_APROVACAO_TROCA_TURMA = true`, a turma anterior continua
  sendo "a atual" em qualquer tela (perfil, ranking, loja) até alguém
  aprovar — o painel admin mostra essas solicitações pendentes em
  `painel.html` quando essa flag está ligada, com um botão "Revisar" que
  abre um modal com tudo que o admin precisa pra decidir (nome, matrícula,
  turma atual, turma solicitada, quando foi pedida, e o histórico completo
  de turmas do aluno) e dois botões, Aprovar/Recusar;
- aprovar grava `aprovado=1` na linha; recusar grava `aprovado=-1` — a
  linha nunca é apagada nem editada além dessa coluna (preserva o
  histórico completo, incluindo decisões recusadas — diferente de um
  cancelamento pelo próprio aluno, que remove a linha). Uma linha recusada
  nunca conta como a turma atual, então recusar uma troca que **já tinha
  entrado em vigor** (por exemplo, com `EXIGIR_APROVACAO_TROCA_TURMA`
  desligada e o admin decidindo desfazer mesmo assim) faz a turma anterior
  do aluno voltar a valer automaticamente;
- fora do painel, também é possível decidir manualmente editando
  `turmas_historico.csv` direto e trocando a coluna `aprovado` (`1` =
  aprovar, `-1` = recusar) na linha correspondente (identificada pelo
  `id`) — o painel só oferece um jeito mais rápido de fazer a mesma coisa.

O aluno só vê a turma mais atual — o histórico completo (todas as turmas
por que ele já passou) é só para uso interno/admin: em
`/admin/atividades.html` e `/admin/pedidos.html`, cada atividade/pedido
mostra a turma que o aluno **tinha na época daquela ação** (não a atual —
ele pode ter trocado depois), com um ícone "ⓘ" que mostra o histórico
completo ao passar o mouse ou clicar.

Na visão do próprio aluno (`perfil.html`), a "turma atual" só conta se for
**deste ano letivo** — se a turma mais recente dele for de um ano anterior
(ele nunca foi realocado pra uma turma deste ano), `api/profile.php`
devolve `turma: ""` em vez da turma velha, e a página mostra um aviso
pedindo pra ele informar a turma atual, com o formulário já aberto (veja
`turma_atual_deste_ano()` em `api/turma_utils.php`). Isso vale só pra essa
visão — `turma_atual_do_aluno()` sem esse filtro continua sendo usada pra
fins administrativos (busca de aluno, turma numa compra/atividade antiga,
e como referência pra decidir retroatividade/forçar aprovação de uma
solicitação nova), onde "a última turma conhecida, mesmo desatualizada"
ainda é informação útil. Mesmo que o valor pedido seja **idêntico** ao do
ano anterior (o aluno continua na mesma turma, só precisa confirmar pro
ano novo), o aluno ainda passa pelo fluxo normal de solicitação — nunca é
assumido automaticamente — e o admin vê a comparação completa
(ex.: "Antes: 1010 (2025). Solicitada: 1010 (2026)") na revisão.

### Primeira turma do ano conta retroativa

Um aluno costuma demorar pra atualizar a turma no início de cada ano
letivo, mesmo já "sabendo" desde o dia 1 qual vai ser a nova turma — por
isso a **primeira** turma de um aluno em cada ano (a primeira linha
aplicada com aquele `ano` — veja `eh_primeira_turma_do_ano()` em
`api/turma_utils.php`) pode contar como se já valesse desde 1º de janeiro
daquele ano, em vez da data real em que foi de fato solicitada. Isso só se
aplica à primeira troca do ano — uma segunda troca no mesmo ano (o aluno
já estava numa turma deste ano e troca de novo) nunca é retroativa.

Cada linha tem duas datas: `data_solicitacao` (quando foi de fato pedida —
puramente informativo, mostrado como "solicitada em") e `data_efetiva`
(a que realmente manda na ordem cronológica — qual é "a turma atual",
e qual turma valia numa compra/atividade antiga). Normalmente as duas são
iguais; numa primeira-turma-do-ano retroativa, `data_efetiva` vira
1º de janeiro daquele ano enquanto `data_solicitacao` continua mostrando a
data real do pedido.

Nesta escola o primeiro dígito da turma é a série (ex.: "2005" = 2ª série,
turma 05). Ao decidir se uma primeira-turma-do-ano é retroativa,
comparamos o primeiro dígito da turma antiga com o da nova:

- **Dígito diferente** (ex.: `2005` → `3010`): avanço de série normal do
  ano novo — sempre retroativo a 1º de janeiro, decidido automaticamente,
  sem admin nenhum envolvido.
- **Dígito igual** (ex.: `2005` → `2007`): ambíguo — pode ser repetência
  de ano (série igual, turma nova) ou uma realocação de verdade no meio do
  ano, e só um humano sabe qual dos dois é. Fica represado (não retroativo
  por padrão) até um admin decidir em `painel.html` — o mesmo modal de
  Aprovar/Recusar mostra um aviso amarelo com os botões "Sim, desde 01/01"
  / "Não, a partir da data pedida" quando isso está em aberto. É uma
  decisão **independente** de aprovar/recusar a troca em si — pode ser
  feita antes, depois, ou mesmo sem nunca aprovar nada explicitamente
  (com `EXIGIR_APROVACAO_TROCA_TURMA` desligada a troca já vale sozinha, e
  só a retroatividade fica pendente).

### Forçando aprovação em casos específicos, mesmo com auto-aplicar ligado

Com `EXIGIR_APROVACAO_TROCA_TURMA = false` (o padrão — a maioria das trocas
auto-aplica sozinha, sem incomodar ninguém esperando um admin), três
situações na **primeira troca do ano** de um aluno ainda assim exigem
aprovação manual, gravando a linha com `aprovacao_forcada=1` em vez de
deixar auto-aplicar (decidido uma vez só, na hora da solicitação, em
`api/turma.php` — nunca recalculado depois, mesmo que as configs mudem):

1. **A troca não é a primeira do aluno neste ano** — `TROCA_TURMA_UMA_LIVRE_POR_ANO`
   em `config.php` (padrão ligada): só a primeira troca do ano pode
   auto-aplicar; qualquer troca adicional no mesmo ano sempre exige
   aprovação, seja lá qual for a combinação de turmas. Existe
   especificamente pra fechar uma brecha: sem essa regra, um aluno poderia
   pedir uma turma qualquer primeiro (auto-aplicada, já que é a primeira
   do ano) e, em seguida, pedir de volta a turma que queria o tempo todo —
   como essa segunda solicitação não seria mais "a primeira do ano", ela
   passaria batido pela regra 3 abaixo mesmo se a turma final fosse
   idêntica à do ano anterior.
2. **O valor pedido é EXATAMENTE igual ao da turma do ano anterior**
   (ex.: `1010` → `1010`) — regra fixa, não é opção nenhuma: é o caso mais
   sujeito a engano de todos (turmas normalmente são reconstituídas todo
   ano; um valor idêntico ano a ano quase sempre merece uma conferência).
3. **Mesma situação ambígua da retroatividade** (primeiro dígito/série
   igual ao ano anterior, valor diferente) — `EXIGIR_APROVACAO_TROCA_SERIE_REPETIDA`
   em `config.php` (padrão ligada): além de deixar a retroatividade
   represada (seção acima), força a troca em si a também esperar
   confirmação humana de que é realmente repetência de ano.

Fora desses três casos (turma nova de verdade, primeiro dígito mudou —
avanço de série normal), a troca segue a regra geral de
`EXIGIR_APROVACAO_TROCA_TURMA` como qualquer outra. No modal de revisão
(`painel.html`), uma troca com aprovação forçada mostra um aviso vermelho
explicando o motivo, reconstruído a partir da comparação entre a turma
anterior e a solicitada — não é um texto gravado no CSV, só uma explicação
pro admin entender por que aquela linha específica não auto-aplicou.

## Ranking: apenas o ano atual, e expiração de saldo

Duas configurações independentes em `config.php`, pensadas para o começo
de um ano letivo novo:

- **`RANKING_APENAS_ANO_ATUAL`** (padrão `true`): o saldo usado no
  ranking (líderes, mestres das moedas, soma por turma) considera só
  atividades e pedidos **deste ano civil** — um aluno que só ganhou
  moedas em anos anteriores aparece com 0 no ranking deste ano, mesmo que
  ainda tenha esse saldo disponível pra gastar na loja. Não afeta o saldo
  real (perfil, loja) — só a métrica mostrada no ranking. Com `false`, o
  ranking volta a usar o saldo acumulado de sempre, sem filtrar por ano.
- **`EXPIRAR_SALDO_ANO_NOVO`** (padrão `false`): afeta o saldo **de
  verdade** (perfil, loja, e por consequência o ranking também). Quando
  ligada, a primeira vez que o saldo de um aluno é calculado em cada ano
  novo, o que sobrou do(s) ano(s) anterior(es) é descontado por uma linha
  **virtual** "Expirado" — nunca gravada em `atividades.csv`, sempre
  recalculada na hora, com valor negativo igual ao saldo que ele tinha, de
  forma que a soma zera. A partir daí esse valor não pode mais ser usado
  na loja. Essa linha aparece no extrato do aluno (perfil) exatamente como
  qualquer outra atividade, só que com o nome "Expirado".

As duas podem ser combinadas ou usadas isoladamente: `RANKING_APENAS_ANO_ATUAL`
sozinha dá um "placar" que reinicia visualmente todo ano sem tocar no saldo
gastável; `EXPIRAR_SALDO_ANO_NOVO` sozinha reinicia a economia de verdade
mas deixa o ranking mostrar o acumulado de sempre; as duas juntas fazem o
ranking e o saldo real coincidirem sempre que o aluno já tiver alguma
atividade neste ano.

Com a config desligada (padrão), essa coluna é ignorada e o site se comporta
exatamente como se toda conta já estivesse aprovada.

## Cache no navegador e em CDN (Last-Modified + ETag)

`api/ranking.php` e `api/config_publica.php` — os dois endpoints públicos,
sem dado pessoal — são cacheáveis, tanto pelo navegador quanto por um CDN
no meio do caminho (ex.: Cloudflare). Usam dois validadores condicionais em
sequência, do mais barato pro mais preciso:

1. **`Last-Modified` / `If-Modified-Since`** — comparamos a data de
   modificação mais recente entre os CSVs envolvidos **e** os arquivos de
   código (`config.php`, `_bootstrap.php`, o endpoint em si) contra o que o
   cliente diz já ter. Se nada mudou desde então, respondemos `304` **sem
   sequer montar a resposta** — nem gastamos tempo lendo/agregando os CSVs
   pra descobrir que o resultado seria descartado mesmo.
2. **`ETag` / `If-None-Match`** — se algum arquivo mudou (ou é a primeira
   visita), montamos a resposta normalmente, mas ela ainda pode ser
   idêntica à que o cliente já tem (ex.: um CSV mudou por causa de um aluno
   que nem aparece nesta resposta específica, como um resgate de outro
   aluno que não está no topo do ranking). Por isso calculamos o `ETag` a
   partir do **conteúdo já gerado**, não da data dos arquivos — se bater
   com o que o cliente mandou, respondemos `304` mesmo já tendo montado o
   corpo, ao menos economizando a banda de mandar de novo.

`Cache-Control` é `public, no-cache`: **`public`** autoriza caches
compartilhados (CDN, proxy) a guardarem a resposta — não só o navegador de
quem pediu — já que o conteúdo é igual pra qualquer visitante; **`no-cache`**
(apesar do nome) permite guardar, mas exige sempre revalidar com a origem
antes de reusar (nunca serve uma cópia "às cegas" sem checar primeiro).

Endpoints com dado pessoal (`api/profile.php`, `api/loja.php`, `auth.php`)
usam `Cache-Control: private, no-store` — **`private`** avisa qualquer cache
compartilhado no meio do caminho pra nunca guardar isso (mesmo que alguma
regra de cache tente forçar), e **`no-store`** é o mesmo pedido pro
navegador: nunca guarda, nunca revalida, sempre busca de novo.

**Dois detalhes que já causaram bug nesta implementação, documentados aqui
para não se repetirem:**

- Nunca gere a resposta cacheável chamando `json_response()` por dentro —
  essa função manda seu próprio `Cache-Control: private, no-store`
  incondicionalmente, e como `header()` substitui um cabeçalho de mesmo
  nome enviado antes, isso silenciosamente sobrescrevia o `public, no-cache`
  que `responder_com_cache()` já tinha mandado (o sintoma: `ETag` presente
  na resposta, mas `Cache-Control: no-store` junto — o navegador nunca
  chega a guardar nada nem manda `If-None-Match` de volta). Por isso
  `responder_com_cache()` monta e devolve a resposta por conta própria, sem
  passar por `json_response()`.
- Por padrão, o PHP manda seus próprios cabeçalhos de cache (`Expires`,
  `Cache-Control`, `Pragma`) sempre que uma sessão é iniciada — inclusive um
  `Expires` no passado (1981), pensado pra nunca deixar cachear nada
  relacionado a sessão. Isso poluiria até respostas que deveriam ser
  cacheáveis. Por isso `_bootstrap.php` chama `session_cache_limiter('')`
  antes de iniciar qualquer sessão — e, de qualquer forma, os endpoints
  públicos agora nem chegam a iniciar sessão nenhuma (veja "Sessões"
  abaixo), então isso nem seria um problema pra eles de novo.

## Sessões

A sessão só é iniciada quando existe alguma razão real pra isso — um
visitante anônimo nas páginas públicas (ranking, config), ou até alguém
abrindo `perfil.html` sem nunca ter feito login, nunca chega a ganhar um
arquivo de sessão no servidor nem um cookie no navegador:

- `sessao_iniciar_se_necessario()` só retoma uma sessão se o navegador já
  mandou um cookie que **pareça** um ID de sessão válido **e** o arquivo
  correspondente já **exista em disco**. Isso é de propósito mais rígido do
  que só checar se o cookie existe: chamar `session_start()` com qualquer
  valor (mesmo um velho, inválido ou inventado) faz o PHP criar um arquivo
  novo na hora, mesmo que a sessão resultante fique vazia — então só vale a
  pena chamar `session_start()` depois de já saber, sem criar nada, que
  existe mesmo uma sessão pra retomar;
- `sessao_iniciar_se_necessario(true)` força o início mesmo sem cookie
  nenhum, e só é chamado no exato momento em que um login dá certo em
  `auth.php` — nunca numa tentativa que falhou, nunca em
  cadastro/redefinição de senha (que não usam `$_SESSION`), nunca numa
  visita anônima a qualquer página.

**Limitação:** a checagem "o arquivo existe?" pressupõe o manipulador de
sessão padrão do PHP (arquivos em disco, nome `sess_<id>`). Se o servidor
estiver configurado para guardar sessões em outro lugar (Redis, banco de
dados, um manipulador customizado via `session_set_save_handler()`), essa
checagem nunca vai encontrar o arquivo e todo mundo vai parecer deslogado
mesmo tendo uma sessão válida. Se for esse o caso, `sessao_iniciar_se_necessario()`
precisa ser adaptada pra perguntar pro manipulador em uso em vez de checar
o arquivo diretamente.

Isso também é o que faz os dois endpoints públicos (`api/ranking.php`,
`api/config_publica.php`) serem de fato cacheáveis por um CDN: como eles
nunca iniciam sessão pra um visitante anônimo, nunca mandam `Set-Cookie` —
e a maioria dos CDNs (Cloudflare incluso) se recusa a cachear qualquer
resposta com `Set-Cookie`, não importa o que o `Cache-Control` diga.

As sessões deste site ficam em `.private/sessoes/` (com seu próprio
`.htaccess` bloqueando acesso via navegador) em vez da pasta de sessão
compartilhada padrão do servidor — se você hospeda vários sites que usam
essa mesma pasta compartilhada, isso evita qualquer conflito de nome de
arquivo entre eles, porque este site nem chega a guardar nada lá. A pasta é
criada sozinha no primeiro request que precisar dela (`@mkdir` em
`_bootstrap.php`), então não depende de nenhum provisionamento manual — o
próprio PHP cuida disso, mesmo que o site tenha sido implantado
dinamicamente sem essa pasta pré-existir.

`session.use_strict_mode` está ligado — sem isso, o PHP aceita de volta um
ID de sessão que o navegador mandou mesmo que esse ID não exista mais no
servidor (por exemplo, logo depois de um logout) e recria um arquivo vazio
com aquele mesmo nome. Isso também é uma boa prática de segurança à parte:
evita ataques de fixação de sessão, onde alguém tenta forçar a vítima a
usar um ID de sessão escolhido por quem ataca.

### Duração do login

`LOGIN_DURACAO_ATIVADA` (padrão `true`) + `LOGIN_DURACAO_SEGUNDOS` (padrão
8 horas) em `config.php`: depois desse tempo **sem nenhuma requisição
autenticada**, a sessão expira sozinha e o aluno precisa logar de novo,
mesmo com o navegador continuando aberto. É uma janela deslizante, não um
prazo fixo desde o login — cada requisição autenticada renova o prazo
(`aplicar_expiracao_login()`, chamada tanto por `require_login()` quanto
diretamente por `api/loja.php`). O cookie de sessão também nasce com esse
mesmo teto de vida, como uma segunda camada de segurança — mas quem decide
de verdade se a sessão ainda vale é sempre a checagem no servidor, não o
que o navegador guardou.

Com `LOGIN_DURACAO_ATIVADA = false`, volta ao comportamento antigo: o
cookie dura até o navegador fechar, sem nenhuma expiração por tempo de
inatividade.

## Atividades para vários alunos de uma vez

A coluna `matriculas` de `atividades.csv` aceita mais de uma matrícula na
mesma linha, separadas por `;` (ex.: `672026;672027;672028`). Uma atividade
dada para uma turma inteira — "Boas notas", por exemplo — pode ser uma
linha só em vez de uma linha repetida para cada aluno. O `valor` da linha é
creditado por inteiro para cada matrícula listada (não é dividido entre
elas).

**Por que só `;` e não `,`?** Vírgula já é o separador de colunas do
próprio CSV. Um valor como `672026,672027` só sobrevive intacto se o campo
inteiro estiver entre aspas — o que o Excel/Google Sheets faz sozinho ao
salvar, mas ninguém garante isso editando o arquivo à mão num editor de
texto simples (um `,` sem aspas quebraria o arquivo em colunas erradas).
Usando só `;` essa ambiguidade nem chega a existir.

## Endpoints da API

- `api/config_publica.php` — **público**, sem dados sensíveis, cacheável.
  Devolve as regras de formulário (senha, matrícula, turma, link do
  WhatsApp, etc.) em JSON, para o navegador nunca duplicar esses valores.
- `api/ranking.php` — **público** (não exige login), cacheável, pensado para
  ser aberto por qualquer visitante como um mural. Alunos/turmas com saldo
  zero nunca aparecem.
  - sem parâmetros (`?view=resumo`, padrão): top 3 líderes por saldo, top 3
    "mestres das moedas" por gasto, e o total de EcoCoins das 5 turmas com
    mais saldo.
  - `?view=completo&limite=N&busca=texto`: lista completa ordenada por
    saldo, paginada por `limite` (máx. `RANKING_LIMITE_MAXIMO`, mesmo que o
    parâmetro peça mais) e filtrável por `busca` (compara com o primeiro
    nome já recortado — ignorada se `MOSTRAR_APENAS_PRIMEIRA_LETRA` estiver
    ligado; restrita ao topo N se `RANKING_BUSCA_LIMITADA` estiver ligado).
- `api/profile.php` — exige login; devolve os dados do aluno autenticado, o
  histórico de atividades e se a conta está ativa.
- `api/loja.php` — exige login para comprar/cancelar; a listagem de itens
  pode ficar pública se `LOJA_VISIVEL_SEM_LOGIN` estiver ligado.
  - `GET` (sem parâmetro `aba`): saldo do aluno e o catálogo de itens ativos
    (ou só o catálogo, sem dado pessoal nenhum, se não estiver logado e a
    config permitir).
  - `GET ?aba=Pedidos Loja`: os pedidos do próprio aluno (exige login), cada
    um já indicando se pode ser cancelado (`cancelavel`).
  - `POST { acao: 'comprar', item_id }`: registra um resgate (valida
    saldo/preço/status da conta no servidor).
  - `POST { acao: 'cancelar', pedido_id }`: cancela um pedido `Pendente` do
    próprio aluno.

## Estrutura do front-end

- `script.js` — utilidades compartilhadas por todas as páginas: menu (com
  logo linkando para `/`), `escapeHtml`, `formatarMoeda`, `formatarTurma`,
  o sistema de avisos em tela (`mostrarAviso`, no canto da tela — substitui
  `alert()` para mensagens informativas) e os validadores de campo em tempo
  real (`restringirSomenteNumeros`/`restringirSomenteLetras`). Também busca
  `api/config_publica.php` uma vez por página (`window.configPromise`) para
  todo o resto do JS usar.
- `script_index.js` — página inicial. Carrossel, eventos e projetos em
  destaque vêm de `api/conteudo_publica.php` (editável em
  `/admin/conteudo.html`) numa única requisição, cacheável como o
  ranking.
- `script_ranking.js` — página de ranking completo. Busca no servidor,
  seletor "Mostrar" que se adapta a `RANKING_LIMITE_MAXIMO` sozinho, e o
  resumo por turma é calculado a partir da mesma lista já carregada para a
  tabela (não faz uma segunda requisição só para isso).
- `script_loja.js` — loja (catálogo dinâmico com ícone/imagem por item,
  resgate, cancelamento e histórico de pedidos).
- `script_login_perfil.js` — login, cadastro (com validação e restrição de
  caracteres nos campos, espelhando `config.php`), recuperação de senha
  (com o código de 6 caracteres) e perfil.

Todas as páginas que usam `<header>` (início, ranking, loja, perfil) têm um
menu estático de reserva já no HTML — se o JavaScript não carregar por
algum motivo, a navegação continua funcionando; o `carregarMenu()` só
substitui esse HTML por uma versão equivalente quando roda. Todo botão que
só navegava para outra página (nunca fazia nada além disso) é um link
`<a href="...">` de verdade, não um `<button onclick="location.href=...">`
— funciona sem JavaScript também. Toda página mostra um aviso
(`<noscript>`) pedindo para habilitar o JavaScript, já que partes do site
(login, cadastro, loja, ranking dinâmico) realmente precisam dele.

## Painel administrativo (`/admin/`)

Existe um painel administrativo completo em `/admin/` — login próprio,
isolado do site de alunos (sessão, cookie, config e log próprios; veja
`admin/.private/README-ADMIN.md` para todos os detalhes). De lá dá pra:

- criar/editar atividades (inclusive uma atividade valendo pra vários
  alunos de uma vez) e escolher os alunos por busca de
  matrícula/nome/turma;
- editar o status dos pedidos da loja;
- criar/editar/ativar/desativar/remover itens da loja;
- editar o carrossel, os eventos em destaque e os projetos da página
  inicial (antes fixos em `dados_index.js`, agora em CSVs próprios —
  `carrossel.csv`, `eventos.csv`, `projetos.csv` — servidos ao site
  público via `api/conteudo_publica.php`, cacheável como o ranking);
- consultar o log de todas as ações administrativas.

O painel admin só tem acesso de ESCRITA às mesmas "bases de dados" (os
CSVs) que o site público já usa — nunca escreve em nenhum outro arquivo.
Toda ação administrativa é registrada.

## Observação sobre as planilhas originais

O código original possui **três endpoints Google Apps Script** distintos:
1. Dados Gerais / Pedidos Loja;
2. Login / Cadastro / redefinição;
3. Perfil por matrícula.

Portanto, embora o site use duas áreas principais de dados, a implementação local
precisa de vários CSVs para representar corretamente as tabelas/abas que antes
eram mantidas pelo Google.
