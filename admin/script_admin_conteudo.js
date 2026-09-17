const cacheConteudo = { carrossel: [], eventos: [], projetos: [] };

const RENDERIZADORES_LINHA = {
    carrossel: (item) => `
        <td>${item.ordem}</td>
        <td>${escapeHtml(item.legenda)}</td>
        <td style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${escapeHtml(item.imagem)}</td>
        <td>${tagStatus(item.ativo)}</td>
        <td>${botoesAcao(item.id, 'carrossel', item.ativo)}</td>`,
    eventos: (item) => `
        <td>${item.ordem}</td>
        <td>${escapeHtml(item.tag)}</td>
        <td>${escapeHtml(item.titulo)}</td>
        <td>${tagStatus(item.ativo)}</td>
        <td>${botoesAcao(item.id, 'eventos', item.ativo)}</td>`,
    projetos: (item) => `
        <td>${item.ordem}</td>
        <td>${escapeHtml(item.titulo)}</td>
        <td>${tagStatus(item.ativo)}</td>
        <td>${botoesAcao(item.id, 'projetos', item.ativo)}</td>`,
};

function tagStatus(ativo) {
    return `<span class="admin-tag ${ativo ? 'ativo' : 'inativo'}">${ativo ? 'Ativo' : 'Inativo'}</span>`;
}

function botoesAcao(id, tipo, ativo) {
    return `
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <button type="button" class="admin-btn secundario pequeno" data-editar="${escapeHtml(id)}" data-tipo-btn="${tipo}">Editar</button>
            <button type="button" class="admin-btn ${ativo ? 'secundario' : 'sucesso'} pequeno" data-toggle="${escapeHtml(id)}" data-tipo-btn="${tipo}" data-ativo="${ativo ? '1' : '0'}">${ativo ? 'Desativar' : 'Ativar'}</button>
            <button type="button" class="admin-btn secundario pequeno" data-duplicar="${escapeHtml(id)}" data-tipo-btn="${tipo}">Duplicar</button>
            <button type="button" class="admin-btn perigo pequeno" data-remover="${escapeHtml(id)}" data-tipo-btn="${tipo}">Remover</button>
        </div>`;
}

let previewImagemCarrossel;

window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-tipo]').forEach(form => {
        form.addEventListener('submit', (e) => salvarConteudo(e, form));
        form.querySelector('.btn-fechar-form').addEventListener('click', () => esconderFormulario(form));
    });

    document.querySelectorAll('.btn-novo').forEach(btn => {
        btn.addEventListener('click', () => abrirFormularioParaCriar(btn.dataset.tipo));
    });

    const formCarrossel = formularioDoTipo('carrossel');
    previewImagemCarrossel = configurarCampoImagem({
        inputArquivo: formCarrossel.querySelector('.campo-imagem-arquivo'),
        inputUrl: formCarrossel.querySelector('.campo-imagem-url'),
        botaoBaixar: formCarrossel.querySelector('.btn-baixar-imagem'),
        botaoRemover: formCarrossel.querySelector('.btn-remover-imagem'),
        campoValor: formCarrossel.querySelector('[data-campo="imagem"]'),
        elementoPreview: formCarrossel.querySelector('.preview-imagem-conteudo'),
    });
    formCarrossel.querySelector('.btn-visualizar-slide').addEventListener('click', alternarPreviewSlide);
    formCarrossel.querySelector('[data-campo="legenda"]').addEventListener('input', atualizarPreviewSlideSeVisivel);
    formCarrossel.querySelector('[data-campo="imagem"]').addEventListener('change', atualizarPreviewSlideSeVisivel);

    ['carrossel', 'eventos', 'projetos'].forEach(carregarConteudo);
});

async function carregarConteudo(tipo) {
    const corpo = document.querySelector(`.corpo-lista[data-tipo="${tipo}"]`);
    try {
        const resposta = await fetch(`api/conteudo.php?tipo=${tipo}`, { credentials: 'same-origin', cache: 'no-store' });
        if (tratarNaoAutenticado(resposta)) return;
        if (!resposta.ok) throw new Error('Erro ao carregar.');
        cacheConteudo[tipo] = await resposta.json();
        renderizarConteudo(tipo);
    } catch (erro) {
        console.error(erro);
        corpo.innerHTML = `<tr><td colspan="5" class="admin-vazio">Não foi possível carregar.</td></tr>`;
    }
}

function renderizarConteudo(tipo) {
    const corpo = document.querySelector(`.corpo-lista[data-tipo="${tipo}"]`);
    const lista = cacheConteudo[tipo];
    const colspan = tipo === 'projetos' ? 4 : 5;

    if (lista.length === 0) {
        corpo.innerHTML = `<tr><td colspan="${colspan}" class="admin-vazio">Nada cadastrado ainda.</td></tr>`;
        return;
    }

    corpo.innerHTML = lista.map(item => `<tr class="${item.ativo ? '' : 'inativo-linha'}">${RENDERIZADORES_LINHA[tipo](item)}</tr>`).join('');

    corpo.querySelectorAll('[data-editar]').forEach(btn => {
        btn.addEventListener('click', () => carregarParaEdicaoConteudo(btn.dataset.tipoBtn, btn.dataset.editar));
    });
    corpo.querySelectorAll('[data-toggle]').forEach(btn => {
        btn.addEventListener('click', () => alternarAtivoConteudo(btn.dataset.tipoBtn, btn.dataset.toggle, btn.dataset.ativo === '1'));
    });
    corpo.querySelectorAll('[data-duplicar]').forEach(btn => {
        btn.addEventListener('click', () => duplicarConteudo(btn.dataset.tipoBtn, btn.dataset.duplicar));
    });
    corpo.querySelectorAll('[data-remover]').forEach(btn => {
        btn.addEventListener('click', () => removerConteudo(btn.dataset.tipoBtn, btn.dataset.remover));
    });
}

function formularioDoTipo(tipo) {
    return document.querySelector(`form[data-tipo="${tipo}"]`);
}

function abrirFormularioParaCriar(tipo) {
    const form = formularioDoTipo(tipo);
    limparFormularioConteudo(form);
    form.style.display = 'block';
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function esconderFormulario(form) {
    form.style.display = 'none';
    limparFormularioConteudo(form);
}

function carregarParaEdicaoConteudo(tipo, id) {
    const item = cacheConteudo[tipo].find(i => i.id === id);
    if (!item) return;
    const form = formularioDoTipo(tipo);

    form.querySelector('.campo-id').value = id;
    form.querySelector('.campo-ordem').value = item.ordem;
    form.querySelectorAll('[data-campo]').forEach(input => {
        input.value = item[input.dataset.campo] || '';
    });

    if (tipo === 'carrossel') {
        form.querySelector('.campo-imagem-url').value = '';
        previewImagemCarrossel.atualizarPreview(item.imagem);
    }

    form.style.display = 'block';
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    atualizarPreviewSlideSeVisivel();
}

function limparFormularioConteudo(form) {
    form.querySelector('.campo-id').value = '';
    form.querySelector('.campo-ordem').value = '1';
    form.querySelectorAll('[data-campo]').forEach(input => input.value = '');

    if (form.dataset.tipo === 'carrossel') {
        form.querySelector('.campo-imagem-url').value = '';
        previewImagemCarrossel.atualizarPreview('');
        form.querySelector('.preview-slide-carrossel').style.display = 'none';
    }
}

function alternarPreviewSlide() {
    const painel = formularioDoTipo('carrossel').querySelector('.preview-slide-carrossel');
    const mostrando = painel.style.display !== 'none';
    painel.style.display = mostrando ? 'none' : 'block';
    if (!mostrando) atualizarPreviewSlideSeVisivel();
}

function atualizarPreviewSlideSeVisivel() {
    const form = formularioDoTipo('carrossel');
    const painel = form.querySelector('.preview-slide-carrossel');
    if (painel.style.display === 'none') return;

    const legenda = form.querySelector('[data-campo="legenda"]').value.trim() || 'Legenda do slide';
    const imagem = form.querySelector('[data-campo="imagem"]').value.trim();

    painel.querySelector('.preview-slide-conteudo').innerHTML = `
        <div class="meus-slides" style="display:block; position:relative;">
            ${imagem
                ? `<img src="${escapeHtml(resolverCaminhoImagemAdmin(imagem))}" alt="" style="width:100%; border-radius:8px;">`
                : `<div style="background:var(--admin-borda); height:100%; display:flex; align-items:center; justify-content:center; color:var(--admin-texto-suave);">Sem imagem</div>`}
            <div class="legenda-slide">${escapeHtml(legenda)}</div>
        </div>`;
}

async function salvarConteudo(e, form) {
    e.preventDefault();
    const tipo = form.dataset.tipo;
    const id = form.querySelector('.campo-id').value;

    const corpo = {
        acao: id ? 'editar' : 'criar',
        tipo, id,
        ordem: Number(form.querySelector('.campo-ordem').value) || 0,
    };
    form.querySelectorAll('[data-campo]').forEach(input => {
        corpo[input.dataset.campo] = input.value.trim();
    });

    try {
        const resposta = await fetch('api/conteudo.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify(corpo)
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Não foi possível salvar.');

        mostrarAviso(resultado.mensagem, 'sucesso');
        esconderFormulario(form);
        carregarConteudo(tipo);
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}

async function alternarAtivoConteudo(tipo, id, estaAtivo) {
    const acao = estaAtivo ? 'desativar' : 'ativar';
    const confirmado = await confirmarAcao(`Tem certeza que deseja ${acao} este item?`, acao === 'ativar' ? 'Ativar' : 'Desativar');
    if (!confirmado) return;

    try {
        const resposta = await fetch('api/conteudo.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ acao, tipo, id })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro.');
        mostrarAviso(resultado.mensagem, 'sucesso');
        carregarConteudo(tipo);
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}

async function duplicarConteudo(tipo, id) {
    const confirmado = await confirmarAcao('Duplicar este item? A cópia nasce desativada — você pode editá-la e ativar depois.', 'Duplicar');
    if (!confirmado) return;

    try {
        const resposta = await fetch('api/conteudo.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ acao: 'duplicar', tipo, id })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro.');
        mostrarAviso(resultado.mensagem, 'sucesso', 6000);
        carregarConteudo(tipo);
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}

async function removerConteudo(tipo, id) {
    const confirmado = await confirmarAcao('Tem certeza que deseja remover este item? Esta ação não pode ser desfeita — considere apenas desativar em vez de remover.', 'Remover');
    if (!confirmado) return;

    try {
        const resposta = await fetch('api/conteudo.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ acao: 'remover', tipo, id })
        });
        const resultado = await resposta.json();
        if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro.');
        mostrarAviso(resultado.mensagem, 'sucesso');
        carregarConteudo(tipo);
    } catch (erro) {
        mostrarAviso(erro.message, 'erro');
    }
}
