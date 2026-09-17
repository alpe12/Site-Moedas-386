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
                <td style="white-space:nowrap;">${escapeHtml(formatarDataHora(l.data))}</td>
                <td>${escapeHtml(l.admin_email)}</td>
                <td>${escapeHtml(l.acao)}</td>
                <td>
                    <span data-resumo="${i}">${escapeHtml(resumo)}</span>
                    ${truncado ? `<button type="button" class="admin-btn secundario pequeno" data-expandir="${i}" style="margin-left:6px;">Ver mais</button>
                    <div data-completo="${i}" style="display:none; margin-top:6px; white-space:pre-wrap; color:var(--admin-texto-suave);">${escapeHtml(detalhes)}</div>` : ''}
                </td>
            </tr>`;
        }).join('');

        corpo.querySelectorAll('[data-expandir]').forEach(btn => {
            btn.addEventListener('click', () => {
                const i = btn.dataset.expandir;
                const resumo = corpo.querySelector(`[data-resumo="${i}"]`);
                const completo = corpo.querySelector(`[data-completo="${i}"]`);
                const mostrandoCompleto = completo.style.display !== 'none';
                completo.style.display = mostrandoCompleto ? 'none' : 'block';
                resumo.style.display = mostrandoCompleto ? 'inline' : 'none';
                btn.textContent = mostrandoCompleto ? 'Ver mais' : 'Ver menos';
            });
        });
    } catch (erro) {
        console.error(erro);
        corpo.innerHTML = '<tr><td colspan="4" class="admin-vazio">Não foi possível carregar o log.</td></tr>';
    }
}
