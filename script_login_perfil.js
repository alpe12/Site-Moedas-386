// ==========================================
// 1. CONFIGURAÇÕES E VARIÁVEIS GLOBAIS
// ==========================================
const alunoLogado = Number(sessionStorage.getItem("alunoLogado"));

const API_URL_perfil = "https://script.google.com/macros/s/AKfycby2Rb7zzG23sCtFLuUIkbXFn_q2gE4LvITZiTqKQ910p6mqTKRogZhd8gWVF4wx5Ns/exec"; 
let dadosAlunosPerfil = [];

const API_URL_login = "https://script.google.com/macros/s/AKfycbzFgqH9ZoG2sPvcH_CHp8xVosVDSe6BiI93DjBLEQSvOI2E8naH7I8xV-X0Km_jOzJA/exec";
let dadosAlunosLogin = [];

// ==========================================
// 2. INICIALIZAÇÃO DO SITE
// ==========================================
window.addEventListener('DOMContentLoaded', async () => {
    await inicializarPerfil();
});

async function inicializarPerfil() {

    const pagina = document.body.id;

    // Se não houver ninguém logado
    if (!alunoLogado) {
        if (pagina === "pagina_perfil") {
            alert("Por favor, faça login primeiro!");
            window.location.href = 'login.html';
        } else if (pagina === "pagina_login") { 
            // CORREÇÃO 3: Só inicializa o login se estiver de fato na página de login
            await carregarPlanilhaLogin();
            await inicializarLogin();
        }
    // Se houver alguém logado, carrega os dados do perfil
    } else {
        if (pagina === "pagina_perfil") {
            const aluno = await carregarPerfilAluno(alunoLogado);
        }
    }
}

// ==========================================
// 3. CONEXÃO COM GOOGLE SHEETS
// ==========================================
async function carregarPlanilhaLogin() {
    try {
        const resposta = await fetch(API_URL_login + "?aba=Dados Gerais");
        dadosAlunosLogin = await resposta.json();
    } catch (erro) {
        console.error("Erro ao carregar planilha:", erro);
        const cache = localStorage.getItem('dadosAlunosCache');
        if (cache) dadosAlunosLogin = JSON.parse(cache);
    }
}

async function carregarPlanilhaPerfil() {
    try {
        const resposta = await fetch(API_URL_perfil + "?aba=Dados Gerais");
        dadosAlunosPerfil = await resposta.json();
    } catch (erro) {
        console.error("Erro ao carregar planilha:", erro);
        const cache = localStorage.getItem('dadosAlunosCache');
        if (cache) dadosAlunosPerfil = JSON.parse(cache);
    }
}

// ==========================================
// 4. CONFIGURAÇÕES DE LOGIN E CADASTRO
// ==========================================
async function inicializarLogin() {
    
    const botao_conta = document.getElementById("btn_conta");
    const botao_entrar = document.getElementById("btn_entrar");
    const botao_cadastrar = document.getElementById("btn_cadastrar");

    // CORREÇÃO 4: Verificações com "if" para o script não quebrar em páginas que não têm esses botões
    if (botao_conta) botao_conta.addEventListener("click", () => {trocaPaginaCadastro();});
    if (botao_entrar) botao_entrar.addEventListener("click", () => {fazerLogin();});
    if (botao_cadastrar) botao_cadastrar.addEventListener("click", () => {fazerCadastro();});
}

async function trocaPaginaCadastro() {
    
    const titulo = document.getElementById("titulo");
    const botao_conta = document.getElementById("btn_conta");
    const login = document.getElementById("login");
    const novaConta = document.getElementById("nova_conta");
    
    if (login.style.display !== "none") {
        login.style.display = "none";
        novaConta.style.display = "block";
        botao_conta.textContent = "Já tenho conta";
        titulo.textContent = "Nova conta";
    } else {
        login.style.display = "block";
        novaConta.style.display = "none";
        botao_conta.textContent = "Não tenho conta";
        titulo.textContent = "Acesse sua conta";
    }
}

// ============================================================
// 5. FUNÇÕES DE LOGIN E CADASTRO (VALIDAÇÃO E ENVIO DE DADOS)
// ============================================================
async function fazerLogin() {

    const email = document.getElementById("email_login").value.trim().toLowerCase();
    const senha = document.getElementById("senha_login").value.trim();

    for (let i = 1; i < dadosAlunosLogin.length; i++) {

        const emailPlanilha = String(dadosAlunosLogin[i][3]).trim().toLowerCase();
        const senhaPlanilha = String(dadosAlunosLogin[i][4]).trim();

        if (emailPlanilha === email && senhaPlanilha === senha) {
            sessionStorage.setItem("alunoLogado", dadosAlunosLogin[i][0]); 
            alert("Login bem-sucedido!");
            window.location.href = 'perfil.html'; 
            return;
        }
    }
    alert("E-mail ou senha incorretos. Tente novamente.");
}

async function fazerCadastro() {

    const nome = document.getElementById("nome_cadastro").value.trim();
    const matricula = document.getElementById("matricula_cadastro").value.trim();
    const turma = document.getElementById("turma_cadastro").value.trim();
    const email = document.getElementById("email_cadastro").value.trim().toLowerCase();
    const senha = document.getElementById("senha_cadastro").value.trim();
    const confSenha = document.getElementById("confsenha").value.trim();

    if (senha !== confSenha) {
        alert("As senhas não coincidem! Por favor, tente novamente.");
        return;
    }
    
    for (let i = 1; i < dadosAlunosLogin.length; i++) {
        const emailPlanilha = String(dadosAlunosLogin[i][3]).trim().toLowerCase();
        if (emailPlanilha === email) {
            alert("E-mail já cadastrado! Por favor, use outro e-mail.");
            return;
        }
        const matriculaPlanilha = String(dadosAlunosLogin[i][0]).trim();
        if (matriculaPlanilha === matricula) {
            alert("Matrícula já cadastrada! Por favor, verifique os dados com a secretaria.");
            return;
        }
    }

    await fetch(API_URL_login, {
        method: "POST",
        body: JSON.stringify({
            nome: nome,
            matricula: matricula,
            turma: turma,
            email: email,
            senha: senha
        })
    });
    
    await carregarPlanilhaPerfil();
    await fetch(API_URL_perfil, {
        method: "POST",
        body: JSON.stringify({
            nome: nome,
            matricula: matricula,
            turma: turma
        })
    });

    alert("Cadastro realizado! Você será redirecionado para a página de login. Aguarde alguns minutos para que os dados sejam sincronizados. Recarregue a página de login se necessário.");
    trocaPaginaCadastro();
}

// ===============================================
// 6. CARREGAMENTO DE DADOS DO PERFIL DO ALUNO
// ===============================================
function formatarData(dataJSON) {
    const data = new Date(dataJSON);
    let texto = data.toLocaleDateString("pt-BR", {
        weekday: "long",
        day: "2-digit",
        month: "2-digit",
        year: "2-digit"
    });
    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

async function carregarPerfilAluno(nomeAba_matricula) {

    const resposta = await fetch(API_URL_perfil + "?aba=" + nomeAba_matricula);

    dadosAluno = await resposta.json();

    document.getElementById("nomeAluno").innerHTML = dadosAluno[1][0]; 
    document.getElementById("turmaAluno").innerHTML = dadosAluno[1][1]; 
    document.getElementById("matriculaAluno").innerHTML = dadosAluno[1][2]; 
    document.getElementById("saldoAluno").innerHTML = `🪙 ${dadosAluno[5][1].toFixed(2)} EcoCoins`; 
    
    const tbody = document.getElementById("atividadesAluno"); 
    tbody.innerHTML = ""; 

    for (let i = 7; i < dadosAluno.length; i++) {

        const data = formatarData(dadosAluno[i][0]); 
        const atividade = dadosAluno[i][1]; 
        const valor = dadosAluno[i][3];     

        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td>${atividade}</td>
            <td>${data}</td>
            <td>${valor > 0 ? "+" : ""}${valor}</td>
        `;
        tbody.appendChild(tr);
    }
    return await dadosAluno;
}

// ============================================================
// 7. FUNÇÃO ADICIONADA: REDEFINIR SENHA DO RECUPERAR.HTML
// ============================================================
async function verificarERedefinir() {
    const matricula = document.getElementById("rec_matricula").value.trim();
    const email = document.getElementById("rec_email").value.trim().toLowerCase();
    const novaSenha = document.getElementById("nova_senha").value.trim();

    if (!matricula || !email || !novaSenha) {
        alert("Por favor, preencha todos os campos!");
        return;
    }

    try {
        const resposta = await fetch(API_URL_login, {
            method: "POST",
            body: JSON.stringify({
                acao: "redefinirSenha",
                matricula: matricula,
                email: email,
                novaSenha: novaSenha
            })
        });

        const resultadoTexto = await resposta.text();
        
        if (resultadoTexto.includes("sucesso")) {
            alert("Senha alterada com sucesso!");
            window.location.href = 'login.html'; 
        } else {
            alert(resultadoTexto); 
        }

    } catch (erro) {
        console.error("Erro ao redefinir senha:", erro);
        alert("Ocorreu um erro ao tentar redefinir a senha. Tente novamente.");
    }
}