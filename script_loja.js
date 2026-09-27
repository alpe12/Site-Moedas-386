let saldoAtualAluno = 0;
let nomeAtualAluno = "";
let cachePedidosDoAluno = [];
let cacheItensLoja = [];

const ICONE_PADRAO = '🎁';

window.addEventListener('DOMContentLoaded', async () => {
    const filtro = document.getElementById('filtroStatusPedido');
    if (filtro) filtro.addEventListener('change', filtrarPedidosPorStatus);

    try {
        const resposta = await fetch('api/loja.php', {
            credentials: 'same-origin',
            cache: 'no-store'
        });

        if (resposta.status === 401) {
            mostrarSecaoDeslogado();
            return;
        }

        if (!resposta.ok) throw new Error("Erro de resposta do servidor.");

        const dadosLoja = await resposta.json();
        cacheItensLoja = Array.isArray(dadosLoja.itens) ? dadosLoja.itens : [];

        if (dadosLoja.autenticado === false) {
            // Modo "loja visível sem login": mostra o catálogo, mas sem
            // saldo/pedidos (isso exige estar logado).
            document.getElementById('secaoPedidos').classList.add('oculto');
            document.getElementById('saldoExibido').innerText = `🪙 Faça login no Perfil para ver seu saldo e resgatar prêmios`;
            renderizarGradeItens(cacheItensLoja, false, 'deslogado');
            return;
        }

        nomeAtualAluno = String(dadosLoja.nome || "").trim();
        saldoAtualAluno = Number(dadosLoja.saldo) || 0;

        document.getElementById('saldoExibido').innerText =
            `Olá, ${nomeAtualAluno} | Seu Saldo: 🪙 ${saldoAtualAluno.toFixed(2)} EcoCoins`;

        renderizarGradeItens(cacheItensLoja, dadosLoja.contaAtiva !== false, 'pendente');
        await carregarPedidosDoAluno();
    } catch (erro) {
        console.error("Erro na inicialização da loja:", erro);
        mostrarSecaoDeslogado();
    }
});

function mostrarSecaoDeslogado() {
    document.getElementById('secaoPedidos').classList.add('oculto');
    document.getElementById('saldoExibido').innerText = `🪙 Faça login para acessar a loja.`;
    document.getElementById('avisoLoginLoja').classList.remove('oculto');
    document.getElementById('grade-itens-loja').classList.add('oculto');
}

function renderizarGradeItens(itens, contaAtiva, motivoBloqueio) {
    const grade = document.getElementById('grade-itens-loja');
    if (!grade) return;

    if (itens.length === 0) {
        grade.innerHTML = `<p class="texto-estado">Nenhum item disponível no momento.</p>`;
        return;
    }

    if (!contaAtiva && motivoBloqueio === 'pendente') {
        mostrarAviso('Sua conta ainda está pendente de aprovação. Você poderá resgatar itens assim que ela for aprovada.', 'info', 7000);
    }

    const textoBotao = contaAtiva ? 'Resgatar' : (motivoBloqueio === 'deslogado' ? 'Faça login' : 'Pendente');

    grade.innerHTML = itens.map(item => `
        <div class="cartao cartao-item-loja" data-item-id="${escapeHtml(item.id)}">
            <div class="icone-item-loja" aria-hidden="true">
                ${item.imagem
                    ? `<img src="${escapeHtml(item.imagem)}" alt="${escapeHtml(item.nome)}">`
                    : escapeHtml(item.icone || ICONE_PADRAO)}
            </div>
            <h3>${escapeHtml(item.nome)}</h3>
            <span class="preco-tag">${formatarMoeda(item.valor)} 🪙</span>
            <button class="botao-resgatar" ${contaAtiva ? '' : 'disabled'}>${textoBotao}</button>
        </div>
    `).join('');

    if (contaAtiva) {
        grade.querySelectorAll('.cartao-item-loja').forEach(cartao => {
            const id = cartao.dataset.itemId;
            const item = itens.find(i => i.id === id);
            cartao.querySelector('.botao-resgatar').addEventListener('click', () => resgatarItem(item));
        });
    }
}

async function carregarPedidosDoAluno() {
    try {
        const resposta = await fetch('api/loja.php?aba=Pedidos%20Loja', {
            credentials: 'same-origin',
            cache: 'no-store'
        });
        if (!resposta.ok) throw new Error("Erro ao buscar histórico da loja");

        const pedidos = await resposta.json();
        cachePedidosDoAluno = Array.isArray(pedidos) ? pedidos.map(p => ({
            id: String(p.id || ""),
            item: String(p.item || "Item"),
            valor: Number(p.valor) || 0,
            status: String(p.status || "Pendente"),
            cancelavel: Boolean(p.cancelavel),
        })) : [];

        filtrarPedidosPorStatus();
    } catch (erro) {
        console.error("Erro ao carregar lista de pedidos:", erro);
        document.getElementById("listaPedidosAluno").innerHTML = `
            <tr><td colspan="4" class="texto-estado texto-estado--tabela texto-estado--erro">
            Não foi possível carregar seu histórico de pedidos.</td></tr>`;
    }
}

function filtrarPedidosPorStatus() {
    const select = document.getElementById('filtroStatusPedido');
    const statusEscolhido = select ? select.value : '';

    const filtrados = (!statusEscolhido || statusEscolhido === 'todos')
        ? cachePedidosDoAluno
        : cachePedidosDoAluno.filter(p => p.status === statusEscolhido);

    renderizarTabelaPedidos(filtrados);
}

function renderizarTabelaPedidos(listaDePedidos) {
    const tbody = document.getElementById("listaPedidosAluno");
    if (!tbody) return;
    tbody.innerHTML = "";

    if (listaDePedidos.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="texto-estado texto-estado--tabela">
            Nenhum pedido encontrado.</td></tr>`;
        return;
    }

    const classesPorStatus = {
        Aprovado: 'status-pedido--aprovado',
        Resgatado: 'status-pedido--resgatado',
        Cancelado: 'status-pedido--cancelado',
    };

    listaDePedidos.forEach(pedido => {
        const classeStatus = classesPorStatus[pedido.status] || '';

        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${escapeHtml(pedido.item)}</td>
            <td>🪙 ${formatarMoeda(pedido.valor)}</td>
            <td>
                <span class="status-pedido ${classeStatus}">
                    ${escapeHtml(pedido.status)}
                </span>
            </td>
            <td>
                ${pedido.cancelavel ? `<button class="botao-cancelar-pedido">Cancelar</button>` : ''}
            </td>`;

        if (pedido.cancelavel) {
            tr.querySelector('.botao-cancelar-pedido').addEventListener('click', () => cancelarPedido(pedido));
        }
        tbody.appendChild(tr);
    });
}

async function cancelarPedido(pedido) {
    if (!pedido || !pedido.id) return;
    if (!confirm(`Cancelar o pedido de "${pedido.item}"? O valor voltará para o seu saldo.`)) return;

    try {
        const resposta = await fetch('api/loja.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ acao: "cancelar", pedido_id: pedido.id })
        });

        const resultado = await resposta.json();

        if (!resposta.ok || !resultado.sucesso) {
            throw new Error(resultado.mensagem || "Não foi possível cancelar o pedido.");
        }

        mostrarAviso(resultado.mensagem || "Pedido cancelado.", 'sucesso');
        setTimeout(() => location.reload(), 1200);
    } catch (erro) {
        console.error("Falha ao cancelar pedido:", erro);
        mostrarAviso(erro.message || "Erro de conexão! Não foi possível cancelar o pedido.", 'erro');
    }
}

async function resgatarItem(item) {
    if (!item) return;

    if (saldoAtualAluno < item.valor) {
        mostrarAviso(`Saldo insuficiente! Você possui 🪙 ${saldoAtualAluno.toFixed(2)}, mas este item custa 🪙 ${Number(item.valor).toFixed(2)}.`, 'erro');
        return;
    }

    if (!confirm(`Confirmar o envio do pedido de "${item.nome}" para a loja?`)) return;

    try {
        const resposta = await fetch('api/loja.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ acao: "comprar", item_id: item.id })
        });

        const resultado = await resposta.json();

        if (!resposta.ok || !resultado.sucesso) {
            throw new Error(resultado.mensagem || "Não foi possível registrar o pedido.");
        }

        mostrarAviso(`Pedido de "${item.nome}" enviado com sucesso e está Pendente.`, 'sucesso');
        setTimeout(() => location.reload(), 1200);
    } catch (erro) {
        console.error("Falha ao registrar pedido:", erro);
        mostrarAviso(erro.message || "Erro de conexão! O pedido não pôde ser computado.", 'erro');
    }
}
