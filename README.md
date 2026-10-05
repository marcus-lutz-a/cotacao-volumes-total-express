# Cotação por Volumes para Total Express

Plugin para WooCommerce que cota o frete da **Total Express** direto no
webservice da transportadora, com o **contrato da própria loja**, sem
intermediários, e **divide o pedido em volumes** de até 30 kg.

> Projeto independente, sem vínculo com a Total Express. Você precisa ter um
> contrato com a Total Express e as credenciais do webservice de cálculo de frete.

---

## Por que existe

Lojas com contrato direto com a Total Express costumam usar um gateway de frete
pago só para mostrar o preço no checkout. Dois problemas comuns:

| Problema | Efeito para o cliente |
|---|---|
| O pedido é cotado como **uma caixa só** | Acima de 30 kg (limite por volume da Total Express), a opção some, justamente nos pedidos maiores |
| Falhas silenciosas de configuração ou de tempo de resposta | A opção some sem nenhum registro do motivo |

Este plugin:

1. **Divide o pedido em volumes** de até 30 kg e 120 × 80 × 60 cm. Uma mesma
   nota fiscal pode ter vários volumes;
2. **Cota cada volume** no webservice SOAP da Total Express;
3. **Aplica as regras de preço** da loja: embalagem por volume e repasse do
   Simples Nacional (opcionais);
4. **Nunca derruba o checkout:** se a Total Express falhar ou demorar, a opção
   não aparece, os demais fretes continuam e o motivo fica no log.

## Recursos

- Divisão em volumes por *first-fit decreasing*, com caixa mínima e folga
  configuráveis.
- Prazo do pedido = maior prazo entre os volumes, mais um prazo adicional
  configurável.
- Cache por volume (padrão 6 h) e cache curto de falhas (5 min).
- Limite de tempo por consulta e por pedido: o checkout não trava.
- **Quatro modos de implantação:** `desligado` → `admin` (só administradores
  veem, marcado como [TESTE]) → `sombra` (calcula e registra, ninguém vê) → `ativo`.
- Tela de teste de cotação com o XML enviado e recebido.
- **Migração da Frenet:** um botão adiciona o método a todas as áreas de entrega
  que já têm a Frenet, para comparar lado a lado antes de desativá-la.
- Log de cada cálculo em WooCommerce → Status → Logs, origem `cvte-total-express`.

## Requisitos

- WordPress 6.4+, WooCommerce 8.0+, PHP 8.0+ (com libsodium, padrão no PHP).
- Contrato com a Total Express e usuário/senha do webservice de cálculo de frete.
- Compatível com HPOS (tabelas de pedidos do WooCommerce).

## Instalação

1. Em **Plugins → Adicionar novo → Enviar plugin**, envie o zip e ative.
2. Em **WooCommerce → Total Express → Credenciais do webservice**, digite o
   usuário e a senha e clique em **Salvar credenciais**.
   Alternativa (tem prioridade), no `wp-config.php`:
   ```php
   define('CVTE_USUARIO', 'usuario-do-webservice');
   define('CVTE_SENHA',   'senha-do-webservice');
   ```
3. Em **Regras**, informe o **código do serviço contratado** (`STD`, `EXP`...).
   Um código fora do contrato é recusado com "Tipo de Servico nao utilizado".
4. Use **Testar uma cotação** para validar a credencial.
5. Adicione o método às áreas de entrega: pelo botão de migração da Frenet ou
   em WooCommerce → Configurações → Entrega.
6. Avance o modo: `admin` → `sombra` → `ativo`.

> **Migrando da Frenet:** adicione o método às áreas **antes** de desativar a
> Frenet (o botão encontra as áreas procurando a Frenet nelas) e mude o modo
> para **ativo** antes de desativá-la.

## Regras de preço

```
base  = soma do frete de cada volume + embalagem × nº de volumes
final = base ÷ (1 − Simples)   modo "dividir"
      = base × (1 + Simples)   modo "multiplicar"
```

Com Simples 0% e embalagem R$ 0 (o padrão), o cliente paga o custo da Total
Express. O modo **"dividir"** faz a loja receber exatamente o custo depois do
imposto; o **"multiplicar"** reproduz a regra da Frenet.

## Segurança

- **Senha nunca em texto puro no banco.** Digitada no painel, ela é guardada
  criptografada (libsodium), com uma chave derivada de `AUTH_KEY` e
  `SECURE_AUTH_KEY` do `wp-config.php`. Um backup do banco sozinho não revela
  a senha, e uma cópia do banco em outro site não consegue lê-la. A senha nunca
  volta para a tela. Sem essas chaves, o plugin se recusa a guardá-la.
- Se as chaves secretas do WordPress forem trocadas, a tela avisa e a senha
  precisa ser digitada de novo.
- Formulários protegidos por permissão (`manage_woocommerce`), *nonce* e
  sanitização; saída escapada (`esc_html`/`esc_attr`); SQL só via
  `$wpdb->prepare`; valores do XML escapados.
- Senha enviada só no cabeçalho HTTP Basic, nunca no corpo nem no log.
- Teto de 2.000 unidades por pedido contra carrinhos absurdos.
- `uninstall.php` remove configurações, credenciais salvas e cache ao excluir.

## Decisões de projeto

**SOAP montado à mão, sem `ext-soap`.** Nem toda hospedagem tem a extensão. O
envelope segue o WSDL oficial e é enviado com `wp_remote_post()`.

**Leitura tolerante da resposta.** Campos lidos por nome, ignorando prefixos de
namespace: uma mudança cosmética do lado da transportadora não derruba o frete.

**Conversão monetária defensiva.** `"22.80"` e `"22,80"` viram 22,80, e
`"1.234,56"` vira 1234,56: decide-se pelo último separador.

**Funções puras.** O empacotador e o cálculo de preço recebem números e
devolvem números: são testados sem WordPress, sem banco e sem internet.

## Testes

```bash
php tests/testes.php
```

60 verificações: conversão monetária, leitura de respostas (sucesso, recusa,
erro SOAP, HTML inesperado), validade e escape do XML, empacotamento, folga da
caixa, teto de unidades, cálculo de preço, cache, autenticação, cache de falhas
e criptografia das credenciais. O arquivo só roda pela linha de comando.

## Limitações conhecidas

- O empacotamento é uma heurística: estima as caixas pelo volume, não simula o
  encaixe físico. A folga padrão (15%) deve ser calibrada com etiquetas reais.
- Cota um serviço por vez (o contratado).
- Somente cotação: registro de coleta e rastreio não estão incluídos.
- Textos em português.

## Licença

GPL-2.0-or-later.

Desenvolvido por Marcus Lutz, com Claude (Anthropic) como par de programação.
