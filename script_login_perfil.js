// ==========================================
// 1. CONFIGURAÇÕES E VARIÁVEIS GLOBAIS
// ==========================================
// MANTÉM A MATRÍCULA COMO TEXTO (PRESERVA ZEROS À ESQUERDA)
const alunoLogado = sessionStorage.getItem("alunoLogado") ? String(sessionStorage.getItem("alunoLogado")).trim() : "";

const API_URL_perfil = "https://script.google.com/macros/s/AKfycby2Rb7zzG23sCtFLuUIkbXFn_q2gE4LvITZiTqKQ910p6mqTKRogZhd8gWVF4wx5Ns/exec"; 
const API_URL_login = "https://script.google.com/macros/s/AKfycbzi47ifZHK2bsMJzkFxz-0JNft0c0fhGU3osG2fRPdDA77PWjHVsfKXXVCcAeCM6urP/exec";

// ==========================================
// 2. INICIALIZAÇÃO DO SITE
// ==========================================
window.addEventListener('DOMContentLoaded', async () => {
    await inicializarPerfil();
});

async function inicializarPerfil() {
    const pagina = document.body.id;

    if (!alunoLogado) {
        if (pagina === "pagina_perfil") {
            alert("Por favor, faça login primeiro!");
            window.location.href = 'login.html';
        } else if (pagina === "pagina_login") { 
            await inicializarLogin();
        }
    } else {
        if (pagina === "pagina_perfil") {
            await carregarPerfilAluno(alunoLogado);
        }
    }
}

// ==========================================
// 3. CONFIGURAÇÕES DE LOGIN E CADASTRO
// ==========================================
async function inicializarLogin() {
    const botao_conta = document.getElementById("btn_conta");
    const botao_entrar = document.getElementById("btn_entrar");
    const botao_cadastrar = document.getElementById("btn_cadastrar");

    if (botao_conta) botao_conta.addEventListener("click", trocaPaginaCadastro);
    if (botao_entrar) botao_entrar.addEventListener("click", fazerLogin);
    if (botao_cadastrar) botao_cadastrar.addEventListener("click", fazerCadastro);
}

function trocaPaginaCadastro() {
    const titulo = document.getElementById("titulo");
    const botao_conta = document.getElementById("btn_conta");
    const login = document.getElementById("login");
    const novaConta = document.getElementById("nova_conta");
    
    if (login.style.display !== "none") {
        login.style.display = "none";
        novaConta.style.display = "block";
        if (botao_conta) botao_conta.textContent = "Já tenho conta";
        if (titulo) titulo.textContent = "Nova conta";
    } else {
        login.style.display = "block";
        novaConta.style.display = "none";
        if (botao_conta) botao_conta.textContent = "Não tenho conta";
        if (titulo) titulo.textContent = "Acesse sua conta";
    }
}

// ============================================================
// 4. FUNÇÃO DE LOGIN (VALIDADA NO SERVIDOR)
// ============================================================
async function fazerLogin() {
    const email = document.getElementById("email_login").value.trim().toLowerCase();
    const senha = document.getElementById("senha_login").value.trim();

    if (!email || !senha) {
        alert("Por favor, informe seu e-mail e sua senha.");
        return;
    }

    try {
        const resposta = await fetch(API_URL_login, {
            method: "POST",
            body: JSON.stringify({
                acao: "login",
                email: email,
                senha: senha
            })
        });

        const resultado = await resposta.json();

        if (resultado.sucesso) {
            // Salva a matrícula como Texto no sessionStorage
            sessionStorage.setItem("alunoLogado", String(resultado.matricula).trim()); 
            alert(`Login bem-sucedido! Bem-vindo(a), ${resultado.nome || ''}.`);
            window.location.href = 'perfil.html'; 
        } else {
            alert(resultado.mensagem || "E-mail ou senha incorretos.");
        }
    } catch (erro) {
        console.error("Erro ao realizar login:", erro);
        alert("Erro ao conectar com o servidor. Tente novamente.");
    }
}

// ============================================================
// 5. FUNÇÃO DE CADASTRO
// ============================================================
async function fazerCadastro() {
    const nome = document.getElementById("nome_cadastro").value.trim();
    const matricula = document.getElementById("matricula_cadastro").value.trim();
    const turma = document.getElementById("turma_cadastro").value.trim();
    const email = document.getElementById("email_cadastro").value.trim().toLowerCase();
    const senha = document.getElementById("senha_cadastro").value.trim();
    const confSenha = document.getElementById("confsenha").value.trim();

    if (!nome || !matricula || !turma || !email || !senha) {
        alert("Por favor, preencha todos os campos do cadastro.");
        return;
    }

    if (senha !== confSenha) {
        alert("As senhas não coincidem!");
        return;
    }

    try {
        // 1. Cadastra na aba Dados Gerais (Login)
        const resposta = await fetch(API_URL_login, {
            method: "POST",
            body: JSON.stringify({
                acao: "cadastro",
                nome: nome,
                matricula: matricula,
                turma: turma,
                email: email,
                senha: senha
            })
        });

        const resultadoTexto = await resposta.text();

        if (resultadoTexto.includes("OK")) {
            // 2. Cria/Inicializa a aba de Perfil do aluno
            try {
                await fetch(API_URL_perfil, {
                    method: "POST",
                    body: JSON.stringify({
                        nome: nome,
                        matricula: matricula,
                        turma: turma
                    })
                });
            } catch (ePerfil) {
                console.warn("Aviso na criação da aba perfil:", ePerfil);
            }

            alert("Cadastro realizado com sucesso! Faça login para continuar.");
            trocaPaginaCadastro();
        } else {
            alert(resultadoTexto);
        }
    } catch (erro) {
        console.error("Erro no cadastro:", erro);
        alert("Erro de conexão ao realizar o cadastro.");
    }
}

// ===============================================
// 6. CARREGAMENTO DO PERFIL DO ALUNO (CORRIGIDO)
// ===============================================
function formatarData(dataJSON) {
    if (!dataJSON) return "";
    const data = new Date(dataJSON);
    if (isNaN(data.getTime())) return String(dataJSON);
    let texto = data.toLocaleDateString("pt-BR", {
        weekday: "long",
        day: "2-digit",
        month: "2-digit",
        year: "2-digit"
    });
    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

async function carregarPerfilAluno(nomeAba_matricula) {
    try {
        const resposta = await fetch(`${API_URL_perfil}?aba=${nomeAba_matricula}`);
        if (!resposta.ok) throw new Error("Erro de resposta na consulta do Perfil");

        const dadosAluno = await resposta.json();

        if (dadosAluno && Array.isArray(dadosAluno) && dadosAluno.length > 5) {
            document.getElementById("nomeAluno").innerText = dadosAluno[1][0] || "Aluno"; 
            document.getElementById("turmaAluno").innerText = dadosAluno[1][1] || ""; 
            document.getElementById("matriculaAluno").innerText = dadosAluno[1][2] || nomeAba_matricula; 
            
            // CONVERSÃO SEGURA DO SALDO (evita quebrar se vier como texto)
            const saldoTexto = String(dadosAluno[5][1] || "0").replace(',', '.');
            const saldoNum = parseFloat(saldoTexto) || 0;
            document.getElementById("saldoAluno").innerText = `🪙 ${saldoNum.toFixed(2)} EcoCoins`; 
            
            const tbody = document.getElementById("atividadesAluno"); 
            tbody.innerHTML = ""; 

            for (let i = 7; i < dadosAluno.length; i++) {
                if (!dadosAluno[i] || !dadosAluno[i][0]) continue;
                const data = formatarData(dadosAluno[i][0]); 
                const atividade = dadosAluno[i][1] || ""; 
                const valor = dadosAluno[i][3] || 0;     

                const tr = document.createElement("tr");
                tr.innerHTML = `
                    <td>${atividade}</td>
                    <td>${data}</td>
                    <td>${valor > 0 ? "+" : ""}${valor}</td>
                `;
                tbody.appendChild(tr);
            }
        } else {
            document.getElementById("nomeAluno").innerText = "Perfil não encontrado";
            document.getElementById("saldoAluno").innerText = "Aba do aluno não encontrada na planilha.";
        }
    } catch (erro) {
        console.error("Erro ao carregar perfil do aluno:", erro);
        document.getElementById("nomeAluno").innerText = "Erro de Sincronização";
        document.getElementById("saldoAluno").innerText = "Verifique a conexão com a planilha.";
    }
}

// ============================================================
// 7. REDEFINIR SENHA
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
        alert("Ocorreu um erro ao tentar redefinir a senha.");
    }
}