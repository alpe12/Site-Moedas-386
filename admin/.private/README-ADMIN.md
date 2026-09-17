# Painel Administrativo — /admin/

Isolado do site público de propósito ("como se fossem dois sites
separados"): sessão própria, cookie próprio (`path=/admin/`, nunca
enviado ao resto do site), config própria, rate-limit próprio, log
próprio. O código de `/admin/` compartilha com o site público apenas:
`api/csv_utils.php` (leitura/escrita/trava de CSV sem nenhum efeito
colateral), `api/turma_utils.php` (funções puras pra interpretar o
histórico de turmas — igualmente sem efeito colateral) e
`api/config_compartilhada.php` (os valores de configuração que os dois
lados precisam conhecer — `EXIGIR_APROVACAO_CONTA` e
`EXIGIR_APROVACAO_TROCA_TURMA`; veja o comentário naquele arquivo para o
porquê de ficar separado dos dois `config.php`). Fora isso, lê os "bancos
de dados" (os CSVs em `.private/` na raiz do site: `usuarios.csv`,
`atividades.csv`, `itens_loja.csv`, `pedidos_loja.csv`, `carrossel.csv`,
`eventos.csv`, `projetos.csv`, `turmas_historico.csv`) e a pasta pública
`uploads/` (imagens), que o painel também escreve quando o admin edita
algo — nunca escreve em nenhum outro arquivo fora de `/admin/` e desses
"bancos de dados" — incluindo `turmas_historico.csv`, que o painel também
escreve (só a coluna `aprovado`, nunca outra) ao aprovar ou recusar uma
troca de turma pendente (`admin/api/turmas.php`).

## Primeiro acesso — criando a primeira conta admin

1. Abra `admin/.private/admin_token.txt` no servidor e copie o token de
   32 caracteres que está lá.
2. Acesse `/admin/index.html`, clique em "Criar conta administrativa" e
   preencha nome, e-mail, senha e esse token.
3. Depois de criar a conta, **o token do arquivo já rotacionou** — se
   precisar criar outra conta ou redefinir uma senha depois, terá que
   abrir o arquivo de novo pra pegar o token novo.

## Token mestre de administração

Um único token (`admin/.private/admin_token.txt`, texto puro, 32
caracteres por padrão — ajustável em `admin/api/config.php`) autoriza
duas ações: criar uma conta admin nova, ou redefinir a senha de uma conta
existente. Depois de qualquer uso bem-sucedido de qualquer uma das duas,
um token novo é gerado sozinho — por isso é "de uso único": quem precisar
fazer a próxima ação (criação OU redefinição) vai precisar olhar o
arquivo de novo. Uma tentativa com token errado nunca gasta o token válido
atual.

Não existe um token individual por administrador (diferente do aluno, que
tem seu próprio `reset_token`) — é sempre este único token compartilhado,
guardado em texto puro porque quem precisa "confirmar" uma redefinição é
quem já tem acesso ao servidor, não um monitor via WhatsApp como no site
de alunos.

## Contas administrativas

`admin/.private/admins.csv`: `email,nome,senha_hash,ativo,criado_em`.

`EXIGIR_APROVACAO_ADMIN` (padrão `false`, em `admin/api/config.php`):
ligado, contas novas nascem com `ativo=0` e não conseguem logar até
alguém trocar pra `1` nesse CSV. A posse do token mestre já é, por si só,
uma barreira forte — esta config é uma camada extra opcional.

## Duração do login

`ADMIN_LOGIN_DURACAO_SEGUNDOS` (padrão 1h) — janela deslizante de
inatividade, como no site de alunos. Marcando "lembrar-me" no login, usa
`ADMIN_LOGIN_LEMBRAR_SEGUNDOS` (padrão 48h) em vez disso. A opção
"lembrar-me" só aparece na tela se esse segundo valor for realmente maior
que o padrão — não precisa de uma config separada só para escondê-la,
basta os dois valores ficarem iguais (ou o de "lembrar" menor).

## Log de ações

Toda ação que muda algo (`admin/api/*.php`, exceto leituras puras) grava
uma linha em `admin/.private/admin_log.csv`: `data,admin_email,acao,detalhes`.
Visualização em `/admin/log.html`, mais recente primeiro. Este CSV só
cresce — se ficar grande demais depois de muito tempo de uso, pode ser
arquivado/rotacionado manualmente (renomeie e crie um novo com só o
cabeçalho).

## Estrutura de páginas

- `index.html` — login / criar conta / redefinir senha.
- `painel.html` — pedidos pendentes (com atalho pra aprovar/cancelar);
  contas pendentes (de aluno e de admin) quando a aprovação manual
  correspondente estiver ligada; e trocas de turma que ainda precisam de
  alguma decisão (aprovar/recusar, e/ou — só num caso específico, veja
  "Primeira turma do ano conta retroativa" no README do site público —
  dizer se contam desde 1º de janeiro), com um botão "Revisar" que abre um
  modal com todo o histórico de turmas do aluno e os botões cabíveis pra
  cada decisão em aberto — veja "Troca de turma" no README do site público
  para o funcionamento completo (`admin/api/turmas.php` é o endpoint por
  trás, com as ações "aprovar", "recusar" e "definir_retroativo").
- `atividades.html` — criar/editar atividades; busca de alunos por
  matrícula/nome/turma pra adicionar a uma atividade; lista de alunos já
  incluídos (nome, matrícula, turma) com remoção confirmada. A turma
  mostrada aqui é a que o aluno tinha **na época daquela atividade**, não a
  atual — um ícone "ⓘ" ao lado mostra o histórico completo se ele já
  trocou de turma alguma vez.
- `loja.html` — catálogo de itens: criar, editar, ativar/desativar,
  remover. "Remover" sempre confirma antes; se o item nunca foi pedido,
  oferece a escolha entre só desativar ou apagar de vez; se já tem pedido
  associado, só desativa (apagar de vez arriscaria o id ser reaproveitado
  depois, corrompendo o histórico desses pedidos) — e confere de novo,
  já dentro do lock de escrita, no momento exato de apagar de vez (cobre o
  caso de um pedido ter sido feito entre abrir a tela e clicar em remover).
- `pedidos.html` — lista completa de pedidos com filtro por status;
  trocar o status de qualquer um. Mostra a turma que o aluno tinha na
  época do pedido (mesmo ícone de histórico de `atividades.html`).
- `conteudo.html` — carrossel de fotos, eventos em destaque e projetos da
  escola que aparecem na página inicial do site público (servidos via
  `api/conteudo_publica.php`, cacheável do mesmo jeito que o ranking).
- `log.html` — histórico de ações administrativas, somente leitura.

## Regras de senha do admin

Separadas das regras do aluno, em `admin/api/config.php`
(`ADMIN_SENHA_MIN_*`) — podem ser mais rígidas, já que uma conta admin tem
muito mais poder que uma conta de aluno.

## Imagens (upload/download local)

Em `loja.html` (itens) e `conteudo.html` (carrossel), a imagem pode vir de
um arquivo enviado do computador ou de uma URL colada — nos dois casos o
**servidor baixa/recebe e guarda uma cópia local** em `uploads/` (na raiz
do site, pública — precisa ser exibida pelo site público). Uma URL colada
nunca é salva "como está": ela só é usada pra buscar a imagem uma vez; o
que fica gravado no CSV é sempre o caminho local.

Os arquivos são nomeados pelo hash do conteúdo (`uploads/<hash>.<ext>`) —
duas imagens idênticas enviadas em momentos diferentes viram o mesmo
arquivo (economiza espaço), e isso é o que torna a limpeza simples: pra
saber se uma imagem ainda "está em uso", só é preciso procurar aquele
caminho exato em `carrossel.csv` e `itens_loja.csv`. Sempre que uma edição
troca a imagem de algo (ou remove a linha inteira), o caminho antigo é
conferido — se não estiver em uso em nenhum outro lugar, o arquivo físico
é apagado (`limpar_imagem_se_orfa()` em `admin/api/_bootstrap.php`).

Sempre que uma imagem já estiver adicionada (seja em um novo cadastro ou na
edição de um item/slide existente), o painel exibe um botão "Remover imagem"
junto da miniatura. Ao clicar, a imagem é removida do formulário e a
prévia é atualizada na hora; caso o arquivo não esteja referenciado em
nenhum outro lugar, a limpeza de arquivos órfãos é acionada no servidor.

Cada tela tem um botão "👁️ Visualizar" que mostra uma prévia de como o
item/slide vai aparecer no site público — usa as folhas de estilo reais
do site (`style.css`, `estilo_loja.css`/`estilo_index.css`, carregadas só
pra essa prévia) pra ficar fiel de verdade, não uma aproximação.

Limite de 5 MB por imagem, só jpg/png/gif/webp são aceitos (conferido pelo
conteúdo real do arquivo, não só pela extensão do nome). Baixar de uma URL
recusa endereços que parecem apontar pra rede interna do servidor
(localhost, IPs privados) — cuidado básico, já que é uma ação exclusiva de
quem já tem conta admin.

## Ativar/desativar/duplicar conteúdo da home

Carrossel, eventos e projetos agora têm uma coluna `ativo` (como os itens
da loja) — só o que está ativo aparece no site público
(`api/conteudo_publica.php` já filtra isso). "Duplicar" cria uma cópia com
um id novo e **sempre desativada** por padrão, pra poder ajustar o texto
com calma antes de publicar. Itens/eventos/projetos inativos aparecem na
lista do painel depois dos ativos, com um estilo visualmente apagado.

## Log com o que de fato mudou

Ações de "editar" (atividade, item, pedido, conteúdo) registram no log só
os campos que realmente mudaram, no formato `campo: "valor antigo" →
"valor novo"` — não só os valores novos. Na tela de log, textos longos
aparecem cortados com um botão "Ver mais" que expande a linha completa.

## Senha: regras visíveis ao digitar

Nos formulários de criar conta e redefinir senha (aluno e admin), a lista
de regras de senha (tamanho mínimo, letras/números/especiais conforme
configurado) aparece embaixo do campo e cada regra fica verde ou vermelha
conforme o que já foi digitado atende ou não. Todo campo de senha também
tem um botão de olho pra mostrar/ocultar o que foi digitado.

## Menos JavaScript necessário

O menu de navegação de cada página do painel já vem pronto no HTML (não é
mais construído inteiramente por JavaScript) — a navegação básica funciona
mesmo que o JavaScript não carregue por algum motivo; o JS só troca o botão
"Sair" por um logout de verdade (que limpa a sessão no servidor) em vez de
só um link. Toda página mostra um aviso pedindo pra habilitar o JavaScript,
já que o resto do painel (formulários, listas, tudo dinâmico) realmente
precisa dele.

`index.html` agora verifica se já existe uma sessão válida e redireciona
direto pro painel — não fica pedindo login de novo pra quem já está
logado. Ao referenciar a tela de login de outro lugar (redirecionamentos
de sessão expirada, etc.), o código usa `/admin/` em vez de
`/admin/index.html` — é a mesma página, mas a barra sozinha é a forma
"correta" de apontar pro índice de uma pasta.

## Buscando alunos sem perder o resultado

Na tela de atividades, adicionar um aluno da lista de busca não fecha mais
a lista nem limpa o campo — o aluno adicionado só fica marcado como "já
adicionado" (sem poder clicar de novo nele), e o resto da lista continua
disponível. Isso deixa buscar por turma e adicionar vários alunos de uma
vez bem mais rápido. A lista só fecha quando se clica fora dela.

## Coisas para conferir

1. Confirme que `/admin/.private/` está bloqueado (o `.htaccess` já está
   lá, mas confira também que o servidor realmente bloqueia pastas
   começando com ponto, como você mencionou).
2. Confirme que a pasta `uploads/` (na raiz do site, fora de `.private/`)
   é gravável pelo PHP — é onde as imagens enviadas/baixadas pelo painel
   ficam guardadas, e ela precisa ser acessível pelo navegador também
   (diferente de `.private/`, não deve estar bloqueada).
3. Assim que criar a primeira conta admin de verdade, considere revisar
   `ADMIN_SENHA_MIN_TAMANHO` e `EXIGIR_APROVACAO_ADMIN` conforme a
   necessidade da escola.
4. Nenhuma parte deste painel foi testada rodando de fato (sem PHP
   disponível durante o desenvolvimento) — teste manualmente um ciclo
   completo (criar atividade, editar item da loja, mudar status de
   pedido, remover item sem/com referência) antes de confiar nele.
