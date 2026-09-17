<?php
declare(strict_types=1);
require __DIR__ . '/api/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

/** Mensagens para os erros que with_locked_csv() pode devolver antes mesmo de rodar a validação de negócio. */
function mensagem_erro_lock(string $erro): ?string {
    return match ($erro) {
        'bloqueado' => 'O sistema está ocupado no momento. Tente novamente em alguns segundos.',
        'arquivo_indisponivel' => 'Não foi possível acessar os dados agora. Tente novamente em instantes.',
        default => null,
    };
}

$data = request_json();
$acao = (string)($data['acao'] ?? '');

if ($acao === 'login') {
    $email = normalize_email((string)($data['email'] ?? ''));
    $senha = (string)($data['senha'] ?? '');

    if ($email === '' || $senha === '') {
        json_response(['sucesso' => false, 'mensagem' => 'E-mail e senha são obrigatórios.'], 400);
    }

    // Limite por e-mail (não por IP puro): evita força bruta numa conta sem
    // bloquear uma escola inteira que compartilha o mesmo IP de saída.
    enforce_rate_limit('login', $email, 8, 300);

    $usuarios = csv_assoc(USERS_CSV);

    foreach ($usuarios as $u) {
        if (normalize_email($u['email']) !== $email) continue;

        if (!password_verify($senha, $u['senha_hash'])) break;

        // Só cria sessão de verdade agora que sabemos que o login deu
        // certo — um visitante que só errou a senha nunca ganha um arquivo
        // de sessão à toa.
        sessao_iniciar_se_necessario(true);
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['matricula'] = trim((string)$u['matricula']);
        $_SESSION['nome'] = (string)$u['nome'];
        $_SESSION['ultima_atividade'] = time();

        json_response([
            'sucesso' => true,
            'matricula' => $_SESSION['matricula'],
            'nome' => $_SESSION['nome'],
            'contaAtiva' => conta_esta_ativa($u),
        ]);
    }

    json_response(['sucesso' => false, 'mensagem' => 'E-mail ou senha incorretos.'], 401);
}

if ($acao === 'cadastro') {
    $nomeRaw = (string)($data['nome'] ?? '');
    $turmaRaw = (string)($data['turma'] ?? '');
    $matricula = trim((string)($data['matricula'] ?? ''));
    $emailRaw = trim((string)($data['email'] ?? ''));
    $senha = (string)($data['senha'] ?? '');

    $nome = sanitize_plain_text($nomeRaw, 80);
    if ($nome === null) {
        json_response(['sucesso' => false, 'mensagem' => 'Nome inválido: use apenas letras, números e pontuação básica.'], 400);
    }
    if (($erro = validar_nome_completo($nome)) !== null) {
        json_response(['sucesso' => false, 'mensagem' => $erro], 400);
    }

    $turma = trim($turmaRaw);
    if (($erro = validar_turma($turma)) !== null) {
        json_response(['sucesso' => false, 'mensagem' => $erro], 400);
    }

    if (($erro = validar_matricula($matricula)) !== null) {
        json_response(['sucesso' => false, 'mensagem' => $erro], 400);
    }

    $email = filter_var(normalize_email($emailRaw), FILTER_VALIDATE_EMAIL);
    if ($email === false) {
        json_response(['sucesso' => false, 'mensagem' => 'E-mail inválido.'], 400);
    }

    if (($erro = validar_senha($senha)) !== null) {
        json_response(['sucesso' => false, 'mensagem' => $erro], 400);
    }

    // Cadastro não é limitado por IP: numa escola, várias contas legítimas
    // costumam vir do mesmo IP de saída (mesma rede/NAT), então um limite
    // por IP acabaria bloqueando a escola inteira por causa de uma turma se
    // cadastrando ao mesmo tempo. Em vez disso, usamos um limite global
    // (compartilhado por todo mundo) só para conter um abuso automatizado
    // de verdade — as checagens de matrícula/e-mail/nome duplicados abaixo
    // já impedem a criação de contas repetidas.
    enforce_rate_limit('cadastro', 'global', 300, 3600, porIp: false);

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $token = gerar_token_recuperacao();
    // Sempre começa pendente (ativo=0), mesmo com EXIGIR_APROVACAO_CONTA
    // desligado — assim dá para saber quais contas já foram confirmadas
    // manualmente. conta_esta_ativa() é quem decide se essa coluna chega a
    // ser exigida, olhando a config no momento da checagem.
    $ativo = '0';
    $nomeNormalizado = mb_strtolower($nome);

    // Verifica duplicidade (matrícula, e-mail e nome completo) e grava sob o
    // mesmo lock, para que dois cadastros simultâneos não passem os dois
    // pela checagem antes de qualquer um escrever (condição de corrida).
    $resultado = with_locked_csv(USERS_CSV, function (array $usuarios) use ($matricula, $email, $nome, $nomeNormalizado, $turma, $hash, $token, $ativo) {
        foreach ($usuarios as $u) {
            if ((string)$u['matricula'] === $matricula) {
                return ['return' => ['erro' => 'matricula_duplicada']];
            }
            if (normalize_email($u['email']) === $email) {
                return ['return' => ['erro' => 'email_duplicado']];
            }
            if (mb_strtolower(trim((string)$u['nome'])) === $nomeNormalizado) {
                return ['return' => ['erro' => 'nome_duplicado']];
            }
        }
        $usuarios[] = [
            'matricula' => $matricula, 'nome' => $nome, 'email' => $email,
            'senha_hash' => $hash, 'reset_token' => $token, 'ativo' => $ativo,
        ];
        return ['rows' => $usuarios, 'return' => ['ok' => true]];
    }, headers_para_arquivo(USERS_CSV));

    if (!is_array($resultado) || !empty($resultado['erro'])) {
        $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
        $comContato = ' Se isso for um engano, fale com um monitor: ' . WHATSAPP_LINK;
        $mensagens = [
            'matricula_duplicada' => 'Essa matrícula já está cadastrada.' . $comContato,
            'email_duplicado' => 'Esse e-mail já está cadastrado.' . $comContato,
            'nome_duplicado' => 'Já existe uma conta cadastrada com esse nome.' . $comContato,
        ];
        $mensagem = $mensagens[$erro] ?? (mensagem_erro_lock($erro) ?? 'Não foi possível salvar o cadastro.');
        json_response(['sucesso' => false, 'mensagem' => $mensagem], 409);
    }

    // A turma entra como a primeira linha de turmas_historico.csv, não mais
    // como uma coluna de usuarios.csv — sempre aprovada de cara (aprovado=1):
    // é o valor inicial que o próprio aluno informou no cadastro, não uma
    // "troca" sujeita a EXIGIR_APROVACAO_TROCA_TURMA (essa config é só para
    // trocas pedidas depois, em perfil.html — veja api/turma.php). A
    // matrícula já está garantida única pelo lock acima, então não há risco
    // de duas requisições simultâneas criarem linhas duplicadas aqui.
    // Sem "turma anterior" nenhuma pra comparar, não há retroatividade nem
    // motivo pra forçar aprovação a decidir aqui — data_efetiva =
    // data_solicitacao, retroativo_definido e aprovado já nascem "1"
    // (nada em aberto), aprovacao_forcada fica "0" (as regras de força só
    // fazem sentido comparando com uma turma anterior, que não existe pra
    // a primeira linha de um aluno).
    $agoraTurma = date('c');
    append_csv(TURMAS_HISTORICO_CSV, [
        'turma-' . bin2hex(random_bytes(4)), $matricula, $turma, (string)ano_letivo_atual(), $agoraTurma, $agoraTurma, '1', '0', '1',
    ], headers_para_arquivo(TURMAS_HISTORICO_CSV));

    // Fora isso, uma conta nova não precisa de nenhuma sincronização extra:
    // sem atividades nem pedidos ainda, o saldo calculado dela já começa em
    // zero automaticamente (veja calcular_saldo_aluno()).

    json_response([
        'sucesso' => true,
        'mensagem' => 'Cadastro realizado com sucesso.',
        // Mostrado ao aluno só nesta resposta — o site nunca mais exibe o
        // token depois disso. Guarde-o: será necessário para redefinir a
        // senha caso ele seja esquecido.
        'tokenRecuperacao' => $token,
        'contaAtiva' => !EXIGIR_APROVACAO_CONTA,
    ]);
}

if ($acao === 'redefinirSenha') {
    $matricula = trim((string)($data['matricula'] ?? ''));
    $email = normalize_email((string)($data['email'] ?? ''));
    $token = strtoupper(trim((string)($data['token'] ?? '')));
    $novaSenha = (string)($data['novaSenha'] ?? '');

    if ($matricula === '' || $email === '' || $token === '' || $novaSenha === '') {
        json_response(['sucesso' => false, 'mensagem' => 'Preencha todos os campos.'], 400);
    }
    if (($erro = validar_senha($novaSenha)) !== null) {
        json_response(['sucesso' => false, 'mensagem' => $erro], 400);
    }

    // Limite por matrícula+e-mail: dificulta tentar adivinhar o token de
    // outra pessoa por força bruta.
    enforce_rate_limit('redefinirSenha', $matricula . '|' . $email, 5, 3600);

    $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
    $resultado = with_locked_csv(USERS_CSV, function (array $usuarios) use ($matricula, $email, $token, $novoHash) {
        $encontrado = false;
        foreach ($usuarios as &$u) {
            if ((string)$u['matricula'] === $matricula
                && normalize_email($u['email']) === $email
                && strtoupper(trim((string)$u['reset_token'])) === $token) {
                $u['senha_hash'] = $novoHash;
                $encontrado = true;
            }
        }
        unset($u);
        if (!$encontrado) return ['return' => ['erro' => 'nao_encontrado']];
        return ['rows' => $usuarios, 'return' => ['ok' => true]];
    }, headers_para_arquivo(USERS_CSV));

    if (!is_array($resultado) || !empty($resultado['erro'])) {
        $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
        $mensagemLock = mensagem_erro_lock($erro);
        $mensagem = $mensagemLock ?? ('Matrícula, e-mail ou código de recuperação não conferem. Se você não tem mais o código, '
            . 'fale com um monitor pelo WhatsApp para confirmar sua identidade: ' . WHATSAPP_LINK);
        json_response(['sucesso' => false, 'mensagem' => $mensagem], 400);
    }

    json_response(['sucesso' => true, 'mensagem' => 'Senha alterada com sucesso!']);
}

json_response(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
