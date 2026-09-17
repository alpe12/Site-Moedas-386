let itensCache = [];
let previewImagemItem;

window.addEventListener('DOMContentLoaded', () => {
    document.getElementById('form-item').addEventListener('submit', salvarItem);
    document.getElementById('btn-cancelar-edicao-item').addEventListener('click', limparFormularioItem);

    previewImagemItem = configurarCampoImagem({
        inputArquivo: document.getElementById('item-imagem-arquivo'),
        inputUrl: document.getElementById('item-imagem-url'),
        botaoBaixar: document.getElementById('item-btn-baixar-url'),
        botaoRemover: document.getElementById('item-btn-remover-imagem'),
        campoValor: document.getElementById('item-imagem'),
        elementoPreview: document.getElementById('item-preview-imagem'),
    });

    document.getElementById('btn-visualizar-item').addEventListener('click', alternarPreviewCard);
    ['item-nome', 'item-valor', 'item-icone'].forEach(id => {
        document.getElementById(id).addEventListener('input', atualizarPreviewCardSeVisivel);
    });
    document.getElementById('item-imagem').addEventListener('change', atualizarPreviewCardSeVisivel);

    carregarItens();
});

function alternarPreviewCard() {
    const painel = document.getElementById('preview-card-item');
    const mostrando = painel.style.display !== 'none';
    painel.style.display = mostrando ? 'none' : 'block';
    if (!mostrando) atualizarPreviewCardSeVisivel();
}

function atualizarPreviewCardSeVisivel() {
    const painel = document.getElementById('preview-card-item');
    if (painel.style.display === 'none') return;

    const nome = document.getElementById('item-nome').value.trim() || 'Nome do item';
    const valor = Number(document.getElementById('item-valor').value) || 0;
    const icone = document.getElementById('item-icone').value.trim();
    const imagem = document.getElementById('item-imagem').value.trim();

    document.getElementById('preview-card-item-conteudo').innerHTML = `
        <div class="cartao cartao-item-loja">
            <div class="icone-item-loja" aria-hidden="true">
                ${imagem
                    ? `<img src="${escapeHtml(resolverCaminhoImagemAdmin(imagem))}" alt="" style="width:100%; height:100%; object-fit:cover; border-radius:inherit;">`
                    : escapeHtml(icone || '🎁')}
            </div>
            <h3>${escapeHtml(nome)}</h3>
            <span class="preco-tag">${formatarMoeda(valor)} 🪙</span>
            <button class="botao-resgatar" disabled>Resgatar</button>
        </div>`;
}

async function carregarItens() {
    const corpo = document.getElementById('corpo-itens');
    try {
        const resposta = await fetch('api/itens.php', { credentials: 'same-origin', cache: 'no-store' });
        if (tratarNaoAutenticado(resposta)) return;
        if (!resposta.ok) throw new Error('Erro ao carregar itens.');
        itensCache = await resposta.json();
        renderizarItens();
    } catch (erro) {
        console.error(erro);
        corpo.innerHTML = '<tr><td colspan="5" class="admin-vazio">Não foi possível carregar os itens.</td></tr>';
    }
}

function renderizarItens() {
    const corpo = document.getElementById('corpo-itens');
    if (itensCache.length === 0) {
        corpo.innerHTML = '<tr><td colspan="5" class="admin-vazio">Nenhum item cadastrado ainda.</td></tr>';
        return;
    }

    corpo.innerHTML = itensCache.map(i => `
        <tr class="${i.ativo ? '' : 'inativo-linha'}">
            <td style="font-size:1.5rem; width:40px;">
                ${i.imagem ? `<img src="${escapeHtml(resolverCaminhoImagemAdmin(i.imagem))}" alt="" style="width:32px; height:32px; object-fit:cover; border-radius:6px;">` : escapeHtml(i.icone || '🎁')}
            </td>
            <td>${escapeHtml(i.nome)}<br><span style="color:var(--admin-texto-suave); font-size:0.75rem;">${escapeHtml(i.id)}</span></td>
            <td>🪙 ${formatarMoeda(i.valor)}</td>
            <td><span class="admin-tag ${i.ativo ? 'ativo' : 'inativo'}">${i.ativo ? 'Ativo' : 'Inativo'}</span></td>
            <td style="white-space:nowrap;">
                <button type="button" class="admin-btn secundario pequeno" data-editar="${escapeHtml(i.id)}">Editar</button>
                <button type="button" class="admin-btn ${i.ativo ? 'secundario' : 'sucesso'} pequeno" data-toggle="${escapeHtml(i.id)}" data-ativo="${i.ativo ? '1' : '0'}">${i.ativo ? 'Desativar' : 'Ativar'}</button>
                <button type="button" class="admin-btn perigo pequeno" data-remover="${escapeHtml(i.id)}">Remover</button>
            </td>
        </tr>
    `).join('');

    corpo.querySelectorAll('[data-editar]').forEach(btn => btn.addEventListener('click', () => carregarItemParaEdicao(btn.dataset.editar)));
    corpo.querySelectorAll('[data-toggle]').forEach(btn => btn.addEventListener('click', () => alternarAtivo(btn.dataset.toggle, btn.dataset.ativo === '1')));
    corpo.querySelectorAll('[data-remover]').forEach(btn => btn.addEventListener('click', () => removerItem(btn.dataset.remover)));
}

function carregarItemParaEdicao(id) {
    const item = itensCache.find(i => i.id === id);
    if (!item) return;

    document.getElementById('item-id').value = item.id;
    document.getElementById('item-id').disabled = true;
    document.getElementById('item-nome').value = item.nome;
    document.getElementById('item-valor').value = item.valor;
    document.getElementById('item-icone').value = item.icone;
    document.getElementById('item-imagem').value = item.imagem;
    document.getElementById('item-imagem-url').value = '';
    previewImagemItem.atualizarPreview(item.imagem);

    document.getElementById('titulo-formulario-item').textContent = 'Editar item';
    document.getElementById('btn-cancelar-edicao-item').style.display = 'inline-block';
    document.querySelector('.admin-cartao').scrollIntoView({ behavior: 'smooth' });
    atualizarPreviewCardSeVisivel();
}

function limparFormularioItem() {
    document.getElementById('form-item').reset();
    document.getElementById('item-id').disabled = false;
    document.getElementById('item-imagem').value = '';
    previewImagemItem.atualizarPreview('');
    document.getElementById('preview-card-item').style.display = 'none';
    document.getElementById('titulo-formulario-item').textContent = 'Novo item';
    document.getElementById('btn-cancelar-edicao-item').style.display = 'none';
}

async function salvarItem(e) {
    e.preventDefault();
    const idCampo = document.getElementById('item-id');
    const editando = idCampo.disabled;

    const corpo = {
        acao: editando ? 'editar' : 'criar',
        id: idCampo.value.trim(),
        nome: document.getElementById('item-nome').value.trim(),
        valor: Number(document.getElementById('item-valor').value),
        icone: document.getElementById('item-icone').value.trim(),
        imagem: document.getElementById('item-imagem').value.trim(),
    };

    try {
        const resposta = await fetch('api/itens.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify(corpo)
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Não foi possível salvar.');

        mostrarAviso(resultado.mensagem, 'sucesso');
        limparFormularioItem();
        carregarItens();
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}

async function alternarAtivo(id, estaAtivo) {
    const acao = estaAtivo ? 'desativar' : 'ativar';
    const confirmado = await confirmarAcao(`Tem certeza que deseja ${acao} este item?`, acao === 'ativar' ? 'Ativar' : 'Desativar');
    if (!confirmado) return;

    try {
        const resposta = await fetch('api/itens.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ acao, id })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro.');
        mostrarAviso(resultado.mensagem, 'sucesso');
        carregarItens();
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}

async function removerItem(id) {
    const confirmado = await confirmarAcao('Tem certeza que deseja remover este item?', 'Remover');
    if (!confirmado) return;

    try {
        const resposta = await fetch('api/itens.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ acao: 'remover', id })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro.');

        if (resultado.resultado === 'sem_referencias') {
            const escolha = await escolherAcao(
                'Este item nunca foi pedido. O que deseja fazer?',
                [
                    { texto: 'Só desativar', valor: 'desativar', classe: 'secundario' },
                    { texto: 'Remover definitivamente', valor: 'remover_definitivo', classe: 'perigo' },
                ]
            );
            if (!escolha) return;

            if (escolha === 'desativar') {
                await alternarAtivo(id, true);
                return;
            }

            // remover_definitivo: o servidor confere de novo na hora, sob
            // lock — cobre o caso de um pedido ter sido feito nesse meio-tempo.
            const respostaFinal = await fetch('api/itens.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({ acao: 'remover_definitivo', id })
            });
            const resultadoFinal = await respostaFinal.json();
            mostrarAviso(resultadoFinal.mensagem || 'Concluído.', resultadoFinal.sucesso ? 'sucesso' : 'erro');
            carregarItens();
            return;
        }

        // 'desativado_por_seguranca' ou qualquer outro caso já resolvido pelo servidor.
        mostrarAviso(resultado.mensagem, 'info', 6000);
        carregarItens();
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}
