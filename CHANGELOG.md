# Histórico de versões

## 0.2.0 — 2026-10-05
Primeira versão pública.

- Cotação direta no webservice SOAP da Total Express (`calcularFrete`), sem depender de `ext-soap`.
- Divisão do pedido em volumes de até 30 kg e 120 × 80 × 60 cm, com caixa mínima e folga configuráveis.
- Regras de preço opcionais: embalagem por volume e repasse do Simples Nacional (modos "dividir" e "multiplicar").
- Prazo do pedido = maior prazo entre os volumes + prazo adicional configurável; sem prazo na resposta, o rótulo não inventa um número.
- Cache por volume (6 h), cache curto de falhas (5 min) e "Limpar cache" compatível com Redis/Memcached.
- Limite de tempo por consulta e por pedido.
- Modos de implantação: desligado, admin, sombra, ativo.
- Credenciais pelo painel, com senha criptografada (libsodium, chave derivada do `wp-config.php`), ou por constantes no `wp-config.php`.
- Tela de teste de cotação com o XML enviado e recebido; botão de migração da Frenet.
- Teto de 2.000 unidades por pedido; `uninstall.php`; testes só pela linha de comando.
- 60 testes automáticos executáveis sem WordPress.
