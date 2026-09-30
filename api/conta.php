<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/*
 * O aluno altera os próprios dados cadastrados (perfil.html, cartão "Meus
 * Dados"): e-mail, CPF, celular e senha.
 *
 * TODA alteração exige a senha atual NA MESMA requisição ("senhaAtual") —
 * mesmo trocar só o celular. Estar com a sessão aberta não basta: quem
 * pegar um computador desbloqueado não consegue trocar o e-mail e a senha
 * (o que tomaria a conta) sem saber a senha de hoje.
 *
 * Corpo (JSON): { senhaAtual, email?, cpf?, telefone?, novaSenha? }
 *   - campo ausente ou igual ao valor gravado = sem mudança;
 *   - cpf/telefone seguem CPF_MODO/TELEFONE_MODO (api/config.php):
 *       'oculto'      -> ignorado (nem aparece no formulário);
 *       'opcional'    -> em branco limpa o campo; preenchido é validado;
 *       'obrigatorio' -> não dá para apagar um valor já preenchido. Uma
 *                        conta antiga que ainda está em branco NÃO é
 *                        obrigada a preencher só para mudar outra coisa
 *                        (o modo vale para cadastros novos — veja
 *                        resolver_edicao_campo()).
 *
 * A senha atual é conferida, os campos validados e o CSV regravado sob o
 * MESMO lock: e-mail duplicado não passa por corrida entre duas contas, e
 * nada é gravado se qualquer parte falhar. Cada alteração vira uma linha
 * em log_usuarios.csv (só admins veem), com CPF/celular por extenso (valor
 * antigo → novo) e sem a senha; uma senha atual errada também é registrada.
 * Trocar a senha encerra todas as outras sessões da conta (a atual continua).
 */

// GET: devolve e-mail, CPF e celular do PRÓPRIO aluno, para preencher o
// formulário. perfil.html só chama isto quando o aluno abre o cartão "Meus
// Dados" — api/profile.php não manda mais esses três campos. Em modo
// 'oculto' (api/config.php) o CPF/celular vai vazio, mesmo que uma conta
// antiga ainda tenha o valor de quando o campo estava ligado.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = require_login();
    foreach (csv_assoc(USERS_CSV) as $u) {
        if ((string)$u['matricula'] !== $user['matricula']) continue;
        json_response([
            'sucesso' => true,
            'email' => (string)($u['email'] ?? ''),
            'cpf' => cpf_modo() === 'oculto' ? '' : (string)($u['cpf'] ?? ''),
            'telefone' => telefone_modo() === 'oculto' ? '' : (string)($u['telefone'] ?? ''),
        ]);
    }
    json_response(['sucesso' => false, 'mensagem' => 'Usuário não encontrado.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sucesso' => false, 'mensagem' => 'Método não permitido.'], 405);
}

function mensagem_erro_lock(string $erro): ?string {
    return match ($erro) {
        'bloqueado' => 'O sistema está ocupado no momento. Tente novamente em alguns segundos.',
        'arquivo_indisponivel' => 'Não foi possível acessar os dados agora. Tente novamente em instantes.',
        default => null,
    };
}

$user = require_login();
$matricula = $user['matricula'];
$data = request_json();

// Cada envio conta, certo ou errado: é também um lugar para tentar
// adivinhar a senha de uma sessão aberta, então o limite é curto.
enforce_rate_limit('alterar_dados', $matricula, 10, 900);

$senhaAtual = (string)($data['senhaAtual'] ?? '');
if ($senhaAtual === '') {
    json_response(['sucesso' => false, 'mensagem' => 'Digite sua senha atual para confirmar as alterações.'], 400);
}

// Validações que não dependem do que está gravado — antes do lock.
$emailNovo = null;
if (array_key_exists('email', $data) && $data['email'] !== null) {
    $emailNovo = filter_var(normalize_email((string)$data['email']), FILTER_VALIDATE_EMAIL);
    if ($emailNovo === false) {
        json_response(['sucesso' => false, 'mensagem' => 'E-mail inválido.'], 400);
    }
}

// A senha nunca passa por trim(): espaços nas pontas fazem parte dela.
$novaSenha = (string)($data['novaSenha'] ?? '');
if ($novaSenha !== '' && ($erro = validar_senha($novaSenha)) !== null) {
    json_response(['sucesso' => false, 'mensagem' => $erro], 400);
}

$cpfEnviado = (array_key_exists('cpf', $data) && $data['cpf'] !== null) ? (string)$data['cpf'] : null;
$telefoneEnviado = (array_key_exists('telefone', $data) && $data['telefone'] !== null) ? (string)$data['telefone'] : null;

$resultado = with_locked_csv(USERS_CSV, function (array $usuarios) use ($matricula, $senhaAtual, $emailNovo, $novaSenha, $cpfEnviado, $telefoneEnviado) {
    $indice = null;
    foreach ($usuarios as $i => $linha) {
        if ((string)$linha['matricula'] === $matricula) { $indice = $i; break; }
    }
    if ($indice === null) return ['return' => ['erro' => 'nao_encontrado']];

    $u = $usuarios[$indice];
    $nome = (string)($u['nome'] ?? '');

    if (!password_verify($senhaAtual, (string)($u['senha_hash'] ?? ''))) {
        return ['return' => ['erro' => 'senha_incorreta', 'nome' => $nome]];
    }

    $detalhes = [];

    if ($emailNovo !== null && $emailNovo !== normalize_email((string)($u['email'] ?? ''))) {
        foreach ($usuarios as $j => $outro) {
            if ($j !== $indice && normalize_email((string)($outro['email'] ?? '')) === $emailNovo) {
                return ['return' => ['erro' => 'email_duplicado', 'nome' => $nome]];
            }
        }
        $detalhes[] = 'e-mail: "' . ($u['email'] ?? '') . '" → "' . $emailNovo . '"';
        $u['email'] = $emailNovo;
    }

    $cpfAtual = (string)($u['cpf'] ?? '');
    [$cpfValor, $erro] = resolver_edicao_campo(cpf_modo(), $cpfEnviado, $cpfAtual, 'CPF', 'validar_cpf', 'formatar_cpf');
    if ($erro !== null) return ['return' => ['erro' => 'campo_invalido', 'mensagem' => $erro, 'nome' => $nome]];
    if ($cpfValor !== null) {
        $detalhes[] = 'CPF: ' . ($cpfAtual === '' ? '(vazio)' : $cpfAtual) . ' → ' . ($cpfValor === '' ? '(vazio)' : $cpfValor);
        $u['cpf'] = $cpfValor;
    }

    $telefoneAtual = (string)($u['telefone'] ?? '');
    [$telefoneValor, $erro] = resolver_edicao_campo(telefone_modo(), $telefoneEnviado, $telefoneAtual, 'celular', 'validar_telefone', 'formatar_telefone');
    if ($erro !== null) return ['return' => ['erro' => 'campo_invalido', 'mensagem' => $erro, 'nome' => $nome]];
    if ($telefoneValor !== null) {
        $detalhes[] = 'celular: ' . ($telefoneAtual === '' ? '(vazio)' : $telefoneAtual) . ' → ' . ($telefoneValor === '' ? '(vazio)' : $telefoneValor);
        $u['telefone'] = $telefoneValor;
    }

    $senhaAlterada = false;
    if ($novaSenha !== '') {
        if (password_verify($novaSenha, (string)$u['senha_hash'])) {
            return ['return' => ['erro' => 'senha_igual', 'nome' => $nome]];
        }
        $u['senha_hash'] = password_hash($novaSenha, PASSWORD_DEFAULT);
        $detalhes[] = 'senha alterada (as outras sessões da conta foram encerradas)';
        $senhaAlterada = true;
    }

    if (!$detalhes) return ['return' => ['erro' => 'nada_a_alterar', 'nome' => $nome]];

    $usuarios[$indice] = $u;
    return ['rows' => $usuarios, 'return' => [
        'ok' => true,
        'nome' => $nome,
        'detalhes' => $detalhes,
        'senhaAlterada' => $senhaAlterada,
        'assinaturaSenha' => assinatura_senha((string)($u['senha_hash'] ?? '')),
        'email' => (string)($u['email'] ?? ''),
        'cpf' => (string)($u['cpf'] ?? ''),
        'telefone' => (string)($u['telefone'] ?? ''),
    ]];
}, headers_para_arquivo(USERS_CSV));

if (!is_array($resultado) || !empty($resultado['erro'])) {
    $erro = is_array($resultado) ? ($resultado['erro'] ?? '') : '';
    $nome = is_array($resultado) ? (string)($resultado['nome'] ?? $user['nome']) : $user['nome'];

    if ($erro === 'senha_incorreta') {
        registrar_log_usuario($matricula, $nome, 'alterar_dados_recusado', 'senha atual incorreta');
        json_response(['sucesso' => false, 'mensagem' => 'Senha atual incorreta.'], 403);
    }

    $mensagens = [
        'nao_encontrado' => ['Usuário não encontrado.', 404],
        'email_duplicado' => ['Esse e-mail já está cadastrado em outra conta.', 409],
        'senha_igual' => ['A nova senha deve ser diferente da senha atual.', 400],
        'nada_a_alterar' => ['Nenhuma alteração para salvar.', 400],
        'campo_invalido' => [(string)($resultado['mensagem'] ?? 'Dados inválidos.'), 400],
    ];
    if (isset($mensagens[$erro])) {
        json_response(['sucesso' => false, 'mensagem' => $mensagens[$erro][0]], $mensagens[$erro][1]);
    }
    json_response(['sucesso' => false, 'mensagem' => mensagem_erro_lock($erro) ?? 'Não foi possível salvar as alterações.'], 503);
}

// Trocou a senha: (1) esta sessão ganha um ID novo e a assinatura da senha
// nova — continua logada, quem acabou de digitar a senha não é expulso; (2)
// todas as OUTRAS sessões da conta (outros aparelhos, ou alguém que tenha
// descoberto a senha antiga) caem na próxima requisição, porque a assinatura
// que elas guardam não bate mais (sessao_confere_com_a_conta(),
// api/_bootstrap.php).
if (!empty($resultado['senhaAlterada'])) {
    session_regenerate_id(true);
    $_SESSION['assinatura_senha'] = $resultado['assinaturaSenha'];
}

registrar_log_usuario($matricula, (string)$resultado['nome'], 'alterar_dados', implode('; ', $resultado['detalhes']));

json_response([
    'sucesso' => true,
    'mensagem' => 'Dados atualizados com sucesso!',
    'senhaAlterada' => (bool)$resultado['senhaAlterada'],
    'email' => $resultado['email'],
    'cpf' => $resultado['cpf'],
    'telefone' => $resultado['telefone'],
]);
