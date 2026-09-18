// ==========================================
// UTILITÁRIOS COMPARTILHADOS DO PAINEL ADMIN
// ==========================================

window.adminConfigPromise = fetch('api/config_publica.php', { cache: 'default' })
    .then(r => r.ok ? r.json() : Promise.reject())
    .catch(() => ({
        senhaMinTamanho: 10, senhaMinLetras: 0, senhaMinNumeros: 0, senhaMinEspeciais: 0,
        tokenTamanho: 32, lembrarMeDisponivel: true,
    }));

function escapeHtml(valor) {
    const div = document.createElement('div');
    div.textContent = valor === undefined || valor === null ? '' : String(valor);
    return div.innerHTML;
}

function formatarMoeda(valor) {
    const numero = Number(valor);
    return (Number.isFinite(numero) ? numero : 0).toFixed(2);
}

function formatarDataHora(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return String(iso);
    return d.toLocaleString('pt-BR');
}

function garantirConteinerAvisos() {
    let c = document.getElementById('conteiner-avisos');
    if (!c) {
        c = document.createElement('div');
        c.id = 'conteiner-avisos';
        c.style.cssText = 'position: fixed; top: 16px; right: 16px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; max-width: 340px;';
        document.body.appendChild(c);
    }
    return c;
}

function mostrarAviso(mensagem, tipo = 'info', duracaoMs = 4500) {
    const conteiner = garantirConteinerAvisos();
    const cores = {
        info: { fundo: '#eff6ff', borda: '#2563eb', texto: '#1e3a8a' },
        sucesso: { fundo: '#f0fdf4', borda: '#16a34a', texto: '#14532d' },
        erro: { fundo: '#fef2f2', borda: '#dc2626', texto: '#7f1d1d' },
    };
    const cor = cores[tipo] || cores.info;

    const aviso = document.createElement('div');
    aviso.style.cssText = `background:${cor.fundo}; border-left:4px solid ${cor.borda}; color:${cor.texto};
        padding:12px 16px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15); font-size:0.88rem;
        font-weight:600; line-height:1.4; opacity:0; transform:translateX(20px); transition:opacity .25s,transform .25s;`;
    aviso.textContent = mensagem;
    conteiner.appendChild(aviso);
    requestAnimationFrame(() => { aviso.style.opacity = '1'; aviso.style.transform = 'translateX(0)'; });
    setTimeout(() => {
        aviso.style.opacity = '0';
        aviso.style.transform = 'translateX(20px)';
        setTimeout(() => aviso.remove(), 300);
    }, duracaoMs);
}

/** Modal de confirmação simples (substitui confirm() nativo pra manter a aparência do painel). */
function confirmarAcao(mensagem, textoBotaoConfirmar = 'Confirmar') {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px;';
        overlay.innerHTML = `
            <div style="background:#fff; border-radius:12px; padding:24px; max-width:380px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.25);">
                <p style="margin:0 0 20px; font-size:0.95rem; color:#1e293b;">${escapeHtml(mensagem)}</p>
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button class="admin-btn secundario" id="admin-confirmar-nao">Cancelar</button>
                    <button class="admin-btn perigo" id="admin-confirmar-sim">${escapeHtml(textoBotaoConfirmar)}</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('#admin-confirmar-nao').addEventListener('click', () => { overlay.remove(); resolve(false); });
        overlay.querySelector('#admin-confirmar-sim').addEventListener('click', () => { overlay.remove(); resolve(true); });
    });
}

/**
 * Modal com múltiplas opções (usado no fluxo de "remover item da loja":
 * desativar vs remover definitivamente). Devolve o "valor" da opção
 * escolhida, ou null se cancelado.
 */
function escolherAcao(mensagem, opcoes) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px;';
        const botoesHtml = opcoes.map((op, i) =>
            `<button class="admin-btn ${escapeHtml(op.classe || 'secundario')}" data-i="${i}">${escapeHtml(op.texto)}</button>`
        ).join('');
        overlay.innerHTML = `
            <div style="background:#fff; border-radius:12px; padding:24px; max-width:420px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.25);">
                <p style="margin:0 0 20px; font-size:0.95rem; color:#1e293b;">${escapeHtml(mensagem)}</p>
                <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                    <button class="admin-btn secundario" id="admin-escolher-cancelar">Cancelar</button>
                    ${botoesHtml}
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('#admin-escolher-cancelar').addEventListener('click', () => { overlay.remove(); resolve(null); });
        overlay.querySelectorAll('[data-i]').forEach(btn => {
            btn.addEventListener('click', () => {
                const valor = opcoes[Number(btn.dataset.i)].valor;
                overlay.remove();
                resolve(valor);
            });
        });
    });
}

/**
 * Link para o perfil completo de um aluno (aluno.html) — usado sempre que
 * um nome/matrícula de aluno aparece numa lista (atividades.html,
 * pedidos.html, painel.html), pra dar um jeito rápido de ver tudo sobre
 * aquele aluno sem precisar buscar de novo.
 */
function linkAluno(matricula, conteudoHtml) {
    return `<a href="aluno.html?matricula=${encodeURIComponent(matricula)}">${conteudoHtml}</a>`;
}

function carregarMenuAdmin() {
    // O <header> já vem pronto no HTML (nav funciona mesmo sem JS) — aqui
    // só troca o botão "Sair" de um link simples pra um logout de verdade
    // (limpa a sessão no servidor antes de redirecionar).
    const botaoSair = document.querySelector('header.admin-header .sair-btn');
    if (!botaoSair) return;

    botaoSair.removeAttribute('onclick');
    botaoSair.addEventListener('click', async () => {
        try { await fetch('logout.php', { method: 'POST', credentials: 'same-origin' }); }
        finally { window.location.href = '/admin/'; }
    });
}

/** Toda página autenticada chama isto: se vier 401 de qualquer fetch, volta pro login. */
function tratarNaoAutenticado(resposta) {
    if (resposta.status === 401) {
        window.location.href = '/admin/';
        return true;
    }
    return false;
}

/**
 * Adiciona um botão de "mostrar/ocultar" a um campo de senha e,
 * opcionalmente, uma listinha de regras que acende verde/vermelho ao
 * digitar. Veja o mesmo helper em script.js (site público) — duplicado de
 * propósito, o painel admin não depende de nenhum arquivo do site público.
 */
function melhorarCampoSenha(input, { checklist = false, regras = null } = {}) {
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'position:relative;';
    input.replaceWith(wrapper);
    wrapper.appendChild(input);
    input.style.paddingRight = '42px';

    const botao = document.createElement('button');
    botao.type = 'button';
    botao.textContent = '👁️';
    botao.setAttribute('aria-label', 'Mostrar senha');
    botao.style.cssText = 'position:absolute; right:4px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:1.15rem; padding:6px; line-height:1;';
    wrapper.appendChild(botao);
    botao.addEventListener('click', () => {
        const mostrando = input.type === 'text';
        input.type = mostrando ? 'password' : 'text';
        botao.textContent = mostrando ? '👁️' : '🙈';
        botao.setAttribute('aria-label', mostrando ? 'Mostrar senha' : 'Ocultar senha');
    });

    if (checklist && regras) {
        const lista = document.createElement('div');
        lista.style.cssText = 'margin-top:8px; font-size:0.8rem; display:flex; flex-direction:column; gap:3px;';
        wrapper.insertAdjacentElement('afterend', lista);

        const itens = [{ testar: v => v.length >= regras.senhaMinTamanho, texto: `Pelo menos ${regras.senhaMinTamanho} caracteres` }];
        if (regras.senhaMinLetras > 0) itens.push({ testar: v => (v.match(/\p{L}/gu) || []).length >= regras.senhaMinLetras, texto: `Pelo menos ${regras.senhaMinLetras} letra(s)` });
        if (regras.senhaMinNumeros > 0) itens.push({ testar: v => (v.match(/[0-9]/g) || []).length >= regras.senhaMinNumeros, texto: `Pelo menos ${regras.senhaMinNumeros} número(s)` });
        if (regras.senhaMinEspeciais > 0) itens.push({ testar: v => (v.match(/[^\p{L}0-9]/gu) || []).length >= regras.senhaMinEspeciais, texto: `Pelo menos ${regras.senhaMinEspeciais} caractere(s) especial(is) (!@#$%^&*)` });

        const render = () => {
            lista.innerHTML = itens.map(item => {
                const ok = item.testar(input.value);
                return `<span style="color:${ok ? '#4ade80' : '#dc2626'};">${ok ? '✓' : '✗'} ${escapeHtml(item.texto)}</span>`;
            }).join('');
        };
        input.addEventListener('input', render);
        render();
    }
}

/** Resolve um caminho de imagem guardado num CSV pra uma URL que funciona a partir de /admin/. */
function resolverCaminhoImagemAdmin(caminho) {
    if (!caminho) return '';
    if (/^https?:\/\//i.test(caminho)) return caminho;
    return `../${caminho}`;
}

/**
 * Liga um upload de arquivo e/ou download por URL a um campo oculto que
 * guarda o caminho local final (o que de fato é salvo no CSV) e a uma
 * prévia em miniatura. O servidor sempre baixa e guarda uma cópia local —
 * a URL digitada nunca é salva como está, só usada pra buscar a imagem.
 */
function configurarCampoImagem({ inputArquivo, inputUrl, botaoBaixar, botaoRemover, campoValor, elementoPreview }) {
    function atualizarPreview(caminho) {
        if (elementoPreview) {
            elementoPreview.innerHTML = caminho
                ? `<img src="${escapeHtml(resolverCaminhoImagemAdmin(caminho))}" alt="" style="width:100%; height:100%; object-fit:cover;">`
                : `<span style="color:var(--admin-texto-suave); font-size:0.7rem; text-align:center; padding:4px;">Sem imagem</span>`;
        }
        if (botaoRemover) {
            botaoRemover.style.display = caminho ? 'inline-block' : 'none';
        }
    }

    async function enviar(opcoesFetch) {
        try {
            const resposta = await fetch('api/imagens.php', opcoesFetch);
            const resultado = await resposta.json();
            if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro ao enviar imagem.');
            campoValor.value = resultado.caminho;
            atualizarPreview(resultado.caminho);
            campoValor.dispatchEvent(new Event('change'));
            mostrarAviso('Imagem salva.', 'sucesso', 2500);
        } catch (erro) {
            mostrarAviso(erro.message, 'erro');
        }
    }

    async function removerImagem() {
        const caminhoAtual = campoValor.value.trim();
        campoValor.value = '';
        if (inputArquivo) inputArquivo.value = '';
        if (inputUrl) inputUrl.value = '';
        atualizarPreview('');
        campoValor.dispatchEvent(new Event('change'));
        mostrarAviso('Imagem removida.', 'info', 2500);

        if (caminhoAtual && caminhoAtual.startsWith('uploads/')) {
            try {
                await fetch('api/imagens.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ acao: 'remover', caminho: caminhoAtual }),
                });
            } catch {
                // Limpeza silenciosa em segundo plano.
            }
        }
    }

    if (inputArquivo) {
        inputArquivo.addEventListener('change', () => {
            const arquivo = inputArquivo.files[0];
            if (!arquivo) return;
            const formData = new FormData();
            formData.append('arquivo', arquivo);
            enviar({ method: 'POST', credentials: 'same-origin', body: formData });
        });
    }

    if (botaoBaixar && inputUrl) {
        botaoBaixar.addEventListener('click', () => {
            const url = inputUrl.value.trim();
            if (!url) { mostrarAviso('Cole uma URL primeiro.', 'erro'); return; }
            enviar({
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url }),
            });
        });
    }

    if (botaoRemover) {
        botaoRemover.addEventListener('click', removerImagem);
    }

    atualizarPreview(campoValor.value);
    return { atualizarPreview, removerImagem };
}

window.addEventListener('load', carregarMenuAdmin);

// ==========================================
// MENU MOBILE (hamburguer) DO PAINEL ADMIN
// ==========================================
document.addEventListener('click', (evento) => {
    const botaoToggle = evento.target.closest('.menu-toggle-admin');
    if (botaoToggle) {
        const header = botaoToggle.closest('header.admin-header');
        if (!header) return;
        const aberto = header.classList.toggle('menu-aberto');
        botaoToggle.setAttribute('aria-expanded', aberto ? 'true' : 'false');
        botaoToggle.textContent = aberto ? '✕' : '☰';
        return;
    }

    const headerAberto = document.querySelector('header.admin-header.menu-aberto');
    if (!headerAberto) return;
    if (evento.target.closest('header.admin-header nav a') || !headerAberto.contains(evento.target)) {
        headerAberto.classList.remove('menu-aberto');
        const botao = headerAberto.querySelector('.menu-toggle-admin');
        if (botao) { botao.setAttribute('aria-expanded', 'false'); botao.textContent = '☰'; }
    }
});

window.addEventListener('resize', () => {
    if (window.innerWidth > 720) {
        document.querySelectorAll('header.admin-header.menu-aberto').forEach(header => {
            header.classList.remove('menu-aberto');
            const botao = header.querySelector('.menu-toggle-admin');
            if (botao) { botao.setAttribute('aria-expanded', 'false'); botao.textContent = '☰'; }
        });
    }
});

// ==========================================
// HISTÓRICO DE TURMA — ícone reutilizado em script_admin_atividades.js e
// script_admin_pedidos.js pra mostrar, ao lado da turma exibida (a que o
// aluno tinha NA ÉPOCA daquela atividade/pedido — veja
// admin/api/atividades.php e pedidos.php), a lista completa de turmas por
// que ele já passou.
// ==========================================

/** codifica um valor em base64 seguro pra ir num atributo HTML sem se preocupar com aspas/unicode. */
function codificarBase64Json(valor) {
    return btoa(unescape(encodeURIComponent(JSON.stringify(valor))));
}
function decodificarBase64Json(texto) {
    return JSON.parse(decodeURIComponent(escape(atob(texto))));
}

/** Rótulo em português pro status de uma linha de histórico de turma. */
function rotuloStatusTurma(status) {
    return { aprovado: 'Aprovada', recusado: 'Recusada', pendente: 'Pendente' }[status] || status;
}

/**
 * Ícone "ⓘ" que mostra o histórico de turmas de um aluno (hover = título
 * nativo do navegador; clique = modal com mais detalhe). Não aparece se o
 * aluno só tem uma turma na vida inteira — não há nada de "histórico" pra
 * mostrar nesse caso. historico já vem no formato de
 * formatar_registro_turma() (api/turma_utils.php): {turma, ano, data, status}.
 */
function iconeHistoricoTurma(historico) {
    if (!Array.isArray(historico) || historico.length <= 1) return '';
    const resumo = historico
        .map(h => `${h.turma} (${h.ano}) — ${rotuloStatusTurma(h.status)}`)
        .join('\n');
    return ` <button type="button" class="icone-historico-turma" title="Histórico de turmas:\n${escapeHtml(resumo)}" data-historico="${codificarBase64Json(historico)}" aria-label="Ver histórico de turmas">ⓘ</button>`;
}

function mostrarHistoricoTurmaModal(historico) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px;';
    const linhas = historico.map(h => `
        <tr>
            <td>${escapeHtml(h.turma)}</td>
            <td>${escapeHtml(String(h.ano))}</td>
            <td>${formatarDataHora(h.data)}</td>
            <td>${escapeHtml(rotuloStatusTurma(h.status))}</td>
        </tr>`).join('');
    overlay.innerHTML = `
        <div style="background:#fff; border-radius:12px; padding:24px; max-width:480px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.25);">
            <h3 style="margin:0 0 14px; font-size:1rem;">Histórico de turmas</h3>
            <table class="admin-tabela" style="width:100%;">
                <thead><tr><th>Turma</th><th>Ano</th><th>Solicitada em</th><th>Status</th></tr></thead>
                <tbody>${linhas}</tbody>
            </table>
            <div style="display:flex; justify-content:flex-end; margin-top:16px;">
                <button class="admin-btn secundario" id="admin-historico-fechar">Fechar</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('#admin-historico-fechar').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
}

/**
 * Modal de decisão pra uma troca de turma (painel.html) — mostra tudo que
 * o admin precisa pra decidir (nome, matrícula, turma anterior/solicitada,
 * quando foi pedida, e o histórico completo do aluno). Duas decisões
 * independentes podem acontecer aqui, cada uma na hora (sem fechar o
 * modal): aprovar/recusar a troca, e — só quando ambíguo — se ela conta
 * retroativa a 1º de janeiro. O admin fecha quando terminar; se alguma
 * decisão foi tomada durante a sessão, a página recarrega ao fechar pra
 * refletir a lista atualizada.
 */
function abrirModalDecisaoTurma(troca) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px;';
    overlay.innerHTML = '<div class="admin-turma-conteudo" style="background:#fff; border-radius:12px; padding:24px; max-width:520px; width:100%; max-height:85vh; overflow-y:auto; box-shadow:0 20px 40px rgba(0,0,0,0.25);"></div>';
    document.body.appendChild(overlay);

    let mudou = false;

    function fechar() {
        overlay.remove();
        if (mudou) location.reload();
    }

    async function decidirAprovacao(acao) {
        try {
            const resposta = await fetch('api/turmas.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({ acao, id: troca.id })
            });
            const resultado = await resposta.json();
            if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro ao registrar a decisão.');
            mostrarAviso(resultado.mensagem, 'sucesso');
            troca.statusAprovacao = acao === 'aprovar' ? 'aprovado' : 'recusado';
            if (acao === 'recusar') troca.retroativoPendente = false;
            mudou = true;
            renderizar();
        } catch (erro) {
            mostrarAviso(erro.message, 'erro');
        }
    }

    async function decidirRetroatividade(retroativo) {
        try {
            const resposta = await fetch('api/turmas.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({ acao: 'definir_retroativo', id: troca.id, retroativo })
            });
            const resultado = await resposta.json();
            if (!resposta.ok || !resultado.sucesso) throw new Error(resultado.mensagem || 'Erro ao registrar a decisão.');
            mostrarAviso(resultado.mensagem, 'sucesso');
            troca.retroativoPendente = false;
            mudou = true;
            renderizar();
        } catch (erro) {
            mostrarAviso(erro.message, 'erro');
        }
    }

    function renderizar() {
        const linhasHistorico = (troca.turmaHistorico || []).map(h => `
            <tr>
                <td>${escapeHtml(h.turma)}</td>
                <td>${escapeHtml(String(h.ano))}</td>
                <td>${formatarDataHora(h.data)}</td>
                <td>${escapeHtml(rotuloStatusTurma(h.status))}${h.retroativo ? ' <span title="Contando desde 1º de janeiro">↩️ retroativo</span>' : ''}</td>
            </tr>`).join('') || '<tr><td colspan="4" class="admin-vazio">Sem histórico.</td></tr>';

        const blocoRetroativo = troca.retroativoPendente ? `
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:14px; margin-bottom:18px;">
                <p style="margin:0 0 10px; font-size:0.85rem;">
                    ⚠️ Esta é a primeira turma do aluno neste ano letivo, mas a série (primeiro número) não
                    mudou — pode ser repetência de ano (série igual, turma nova) ou só uma realocação no meio
                    do ano. Deve contar como se já valesse desde 1º de janeiro de ${escapeHtml(String(troca.ano))}?
                </p>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button class="admin-btn secundario pequeno" id="admin-turma-retro-sim">Sim, desde 01/01</button>
                    <button class="admin-btn secundario pequeno" id="admin-turma-retro-nao">Não, a partir da data pedida</button>
                </div>
            </div>` : '';

        const blocoMotivo = troca.aprovacaoForcada && troca.motivoAprovacaoForcada ? `
            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px; margin-bottom:18px; font-size:0.82rem;">
                🔒 Esta troca exige aprovação mesmo com o auto-aplicar geral ligado: ${escapeHtml(troca.motivoAprovacaoForcada)}
            </div>` : '';

        const blocoAcoes = troca.statusAprovacao === 'pendente'
            ? `<button class="admin-btn perigo" id="admin-turma-recusar">Recusar</button>
               <button class="admin-btn sucesso" id="admin-turma-aprovar">Aprovar</button>`
            : `<span style="align-self:center; color:var(--admin-texto-suave); font-size:0.85rem;">Aprovação: ${escapeHtml(rotuloStatusTurma(troca.statusAprovacao))}.</span>`;

        const turmaAnteriorTexto = troca.turmaAnterior
            ? `${escapeHtml(troca.turmaAnterior)} (${escapeHtml(String(troca.turmaAnteriorAno ?? '?'))})`
            : '(nenhuma)';

        const conteudo = overlay.querySelector('.admin-turma-conteudo');
        conteudo.innerHTML = `
            <h3 style="margin:0 0 4px; font-size:1.05rem;">Troca de turma</h3>
            <p style="margin:0 0 16px; color:var(--admin-texto-suave); font-size:0.85rem;">Revise as informações antes de decidir.</p>
            <table class="admin-tabela" style="width:100%; margin-bottom:18px;">
                <tr><th style="width:40%;">Nome</th><td>${escapeHtml(troca.nome)}</td></tr>
                <tr><th>Matrícula</th><td>${escapeHtml(troca.matricula)}</td></tr>
                <tr><th>Antes</th><td>${turmaAnteriorTexto}</td></tr>
                <tr><th>Solicitada</th><td>${escapeHtml(troca.turmaSolicitada)} (${escapeHtml(String(troca.ano))})</td></tr>
                <tr><th>Solicitada em</th><td>${formatarDataHora(troca.data)}</td></tr>
            </table>
            ${blocoMotivo}
            ${blocoRetroativo}
            <p style="margin:0 0 8px; font-weight:600; font-size:0.85rem;">Histórico completo de turmas deste aluno</p>
            <table class="admin-tabela" style="width:100%;">
                <thead><tr><th>Turma</th><th>Ano</th><th>Solicitada em</th><th>Status</th></tr></thead>
                <tbody>${linhasHistorico}</tbody>
            </table>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px; flex-wrap:wrap;">
                <button class="admin-btn secundario" id="admin-turma-fechar">Fechar</button>
                ${blocoAcoes}
            </div>`;

        conteudo.querySelector('#admin-turma-fechar').addEventListener('click', fechar);
        conteudo.querySelector('#admin-turma-aprovar')?.addEventListener('click', () => decidirAprovacao('aprovar'));
        conteudo.querySelector('#admin-turma-recusar')?.addEventListener('click', () => decidirAprovacao('recusar'));
        conteudo.querySelector('#admin-turma-retro-sim')?.addEventListener('click', () => decidirRetroatividade(true));
        conteudo.querySelector('#admin-turma-retro-nao')?.addEventListener('click', () => decidirRetroatividade(false));
    }

    renderizar();
}

document.addEventListener('click', (evento) => {
    const botao = evento.target.closest('.icone-historico-turma');
    if (!botao) return;
    try {
        mostrarHistoricoTurmaModal(decodificarBase64Json(botao.dataset.historico));
    } catch { /* dado malformado — ignora silenciosamente, não é crítico */ }
});
