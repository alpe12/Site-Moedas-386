window.addEventListener('DOMContentLoaded', carregarLog);

async function carregarLog() {
    const corpo = document.getElementById('corpo-log');
    try {
        const resposta = await fetch('api/log.php?limite=300', { credentials: 'same-origin', cache: 'no-store' });
        if (tratarNaoAutenticado(resposta)) return;
        if (!resposta.ok) throw new Error('Erro ao carregar log.');
        const linhas = await resposta.json();

        if (!Array.isArray(linhas) || linhas.length === 0) {
            corpo.innerHTML = '<tr><td colspan="4" class="admin-vazio">Nenhuma ação registrada ainda.</td></tr>';
            return;
        }

        corpo.innerHTML = linhas.map((l, i) => {
            const detalhes = String(l.detalhes || '');
            const truncado = detalhes.length > 80;
            const resumo = truncado ? detalhes.slice(0, 80) + '…' : detalhes;
            return `
            <tr>
                <td class="admin-nowrap">${escapeHtml(formatarDataHora(l.data))}</td>
                <td>${escapeHtml(l.admin_email)}</td>
                <td>${escapeHtml(l.acao)}</td>
                <td>
                    <span data-resumo="${i}">${escapeHtml(resumo)}</span>
                    ${truncado ? `<button type="button" class="admin-btn secundario pequeno" data-expandir="${i}">Ver mais</button>
                    <div data-completo="${i}" class="admin-log-detalhe oculto">${escapeHtml(detalhes)}</div>` : ''}
                </td>
            </tr>`;
        }).join('');

        corpo.querySelectorAll('[data-expandir]').forEach(btn => {
            btn.addEventListener('click', () => {
                const i = btn.dataset.expandir;
                const resumo = corpo.querySelector(`[data-resumo="${i}"]`);
                const completo = corpo.querySelector(`[data-completo="${i}"]`);
                const estaOculto = completo.classList.contains('oculto');
                completo.classList.toggle('oculto', !estaOculto);
                resumo.classList.toggle('oculto', estaOculto);
                btn.textContent = estaOculto ? 'Ver menos' : 'Ver mais';
            });
        });
    } catch (erro) {
        console.error(erro);
        corpo.innerHTML = '<tr><td colspan="4" class="admin-vazio">Não foi possível carregar o log.</td></tr>';
    }
}
