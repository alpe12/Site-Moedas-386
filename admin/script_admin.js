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
        c.className = 'conteiner-avisos';
        document.body.appendChild(c);
    }
    return c;
}

function mostrarAviso(mensagem, tipo = 'info', duracaoMs = 4500) {
    const conteiner = garantirConteinerAvisos();
    const classesPorTipo = { info: 'aviso-toast--info', sucesso: 'aviso-toast--sucesso', erro: 'aviso-toast--erro' };
    const classeTipo = classesPorTipo[tipo] || classesPorTipo.info;

    const aviso = document.createElement('div');
    aviso.className = `aviso-toast ${classeTipo}`;
    aviso.textContent = mensagem;
    conteiner.appendChild(aviso);
    requestAnimationFrame(() => aviso.classList.add('aviso-toast--visivel'));
    setTimeout(() => {
        aviso.classList.remove('aviso-toast--visivel');
        setTimeout(() => aviso.remove(), 300);
    }, duracaoMs);
}

/** Modal de confirmação simples (substitui confirm() nativo pra manter a aparência do painel). */
function confirmarAcao(mensagem, textoBotaoConfirmar = 'Confirmar') {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'admin-modal-overlay';
        overlay.innerHTML = `
            <div class="admin-modal-cartao">
                <p class="admin-modal-texto">${escapeHtml(mensagem)}</p>
                <div class="admin-modal-acoes">
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
        overlay.className = 'admin-modal-overlay';
        const botoesHtml = opcoes.map((op, i) =>
            `<button class="admin-btn ${escapeHtml(op.classe || 'secundario')}" data-i="${i}">${escapeHtml(op.texto)}</button>`
        ).join('');
        overlay.innerHTML = `
            <div class="admin-modal-cartao admin-modal-cartao--largo">
                <p class="admin-modal-texto">${escapeHtml(mensagem)}</p>
                <div class="admin-modal-acoes">
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
    wrapper.className = 'wrapper-senha';
    input.replaceWith(wrapper);
    wrapper.appendChild(input);

    const botao = document.createElement('button');
    botao.type = 'button';
    botao.textContent = '👁️';
    botao.setAttribute('aria-label', 'Mostrar senha');
    botao.className = 'botao-mostrar-senha';
    wrapper.appendChild(botao);
    botao.addEventListener('click', () => {
        const mostrando = input.type === 'text';
        input.type = mostrando ? 'password' : 'text';
        botao.textContent = mostrando ? '👁️' : '🙈';
        botao.setAttribute('aria-label', mostrando ? 'Mostrar senha' : 'Ocultar senha');
    });

    if (checklist && regras) {
        const lista = document.createElement('div');
        lista.className = 'checklist-senha';
        wrapper.insertAdjacentElement('afterend', lista);

        const itens = [{ testar: v => v.length >= regras.senhaMinTamanho, texto: `Pelo menos ${regras.senhaMinTamanho} caracteres` }];
        if (regras.senhaMinLetras > 0) itens.push({ testar: v => (v.match(/\p{L}/gu) || []).length >= regras.senhaMinLetras, texto: `Pelo menos ${regras.senhaMinLetras} letra(s)` });
        if (regras.senhaMinNumeros > 0) itens.push({ testar: v => (v.match(/[0-9]/g) || []).length >= regras.senhaMinNumeros, texto: `Pelo menos ${regras.senhaMinNumeros} número(s)` });
        if (regras.senhaMinEspeciais > 0) itens.push({ testar: v => (v.match(/[^\p{L}0-9]/gu) || []).length >= regras.senhaMinEspeciais, texto: `Pelo menos ${regras.senhaMinEspeciais} caractere(s) especial(is) (!@#$%^&*)` });

        const render = () => {
            lista.innerHTML = itens.map(item => {
                const ok = item.testar(input.value);
                return `<span class="${ok ? 'regra-ok' : 'regra-erro'}">${ok ? '✓' : '✗'} ${escapeHtml(item.texto)}</span>`;
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
                ? `<img src="${escapeHtml(resolverCaminhoImagemAdmin(caminho))}" alt="" class="admin-preview-imagem">`
                : `<span class="admin-preview-vazio">Sem imagem</span>`;
        }
        if (botaoRemover) {
            botaoRemover.classList.toggle('oculto', !caminho);
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
    overlay.className = 'admin-modal-overlay';
    const linhas = historico.map(h => `
        <tr>
            <td>${escapeHtml(h.turma)}</td>
            <td>${escapeHtml(String(h.ano))}</td>
            <td>${formatarDataHora(h.data)}</td>
            <td>${escapeHtml(rotuloStatusTurma(h.status))}</td>
        </tr>`).join('');
    overlay.innerHTML = `
        <div class="admin-modal-cartao admin-modal-cartao--historico">
            <h3>Histórico de turmas</h3>
            <table class="admin-tabela">
                <thead><tr><th>Turma</th><th>Ano</th><th>Solicitada em</th><th>Status</th></tr></thead>
                <tbody>${linhas}</tbody>
            </table>
            <div class="admin-modal-acoes">
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
    overlay.className = 'admin-modal-overlay';
    overlay.innerHTML = '<div class="admin-modal-cartao admin-modal-cartao--turma admin-turma-conteudo"></div>';
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
            <div class="admin-aviso-retroativo">
                <p>
                    ⚠️ Esta é a primeira turma do aluno neste ano letivo, mas a série (primeiro número) não
                    mudou — pode ser repetência de ano (série igual, turma nova) ou só uma realocação no meio
                    do ano. Deve contar como se já valesse desde 1º de janeiro de ${escapeHtml(String(troca.ano))}?
                </p>
                <div class="admin-botoes-linha">
                    <button class="admin-btn secundario pequeno" id="admin-turma-retro-sim">Sim, desde 01/01</button>
                    <button class="admin-btn secundario pequeno" id="admin-turma-retro-nao">Não, a partir da data pedida</button>
                </div>
            </div>` : '';

        const blocoMotivo = troca.aprovacaoForcada && troca.motivoAprovacaoForcada ? `
            <div class="admin-aviso-motivo-forcado">
                🔒 Esta troca exige aprovação mesmo com o auto-aplicar geral ligado: ${escapeHtml(troca.motivoAprovacaoForcada)}
            </div>` : '';

        const blocoAcoes = troca.statusAprovacao === 'pendente'
            ? `<button class="admin-btn perigo" id="admin-turma-recusar">Recusar</button>
               <button class="admin-btn sucesso" id="admin-turma-aprovar">Aprovar</button>`
            : `<span class="admin-status-simples">Aprovação: ${escapeHtml(rotuloStatusTurma(troca.statusAprovacao))}.</span>`;

        const turmaAnteriorTexto = troca.turmaAnterior
            ? `${escapeHtml(troca.turmaAnterior)} (${escapeHtml(String(troca.turmaAnteriorAno ?? '?'))})`
            : '(nenhuma)';

        const conteudo = overlay.querySelector('.admin-turma-conteudo');
        conteudo.innerHTML = `
            <h3>Troca de turma</h3>
            <p>Revise as informações antes de decidir.</p>
            <table class="admin-tabela admin-tabela--info">
                <tr><th>Nome</th><td>${escapeHtml(troca.nome)}</td></tr>
                <tr><th>Matrícula</th><td>${escapeHtml(troca.matricula)}</td></tr>
                <tr><th>Antes</th><td>${turmaAnteriorTexto}</td></tr>
                <tr><th>Solicitada</th><td>${escapeHtml(troca.turmaSolicitada)} (${escapeHtml(String(troca.ano))})</td></tr>
                <tr><th>Solicitada em</th><td>${formatarDataHora(troca.data)}</td></tr>
            </table>
            ${blocoMotivo}
            ${blocoRetroativo}
            <p class="admin-subtitulo-historico">Histórico completo de turmas deste aluno</p>
            <table class="admin-tabela">
                <thead><tr><th>Turma</th><th>Ano</th><th>Solicitada em</th><th>Status</th></tr></thead>
                <tbody>${linhasHistorico}</tbody>
            </table>
            <div class="admin-modal-acoes">
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

// ==========================================
// EMOJI FALLBACK LOADER
// ==========================================
(function() {
    const s = document.createElement('script');
    s.src = '../emoji-fallback.js';
    document.head.appendChild(s);
})();
