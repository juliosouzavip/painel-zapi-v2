# Contabilidade VIP Turismo — atualização de 19/08/2026

## 1. A IA já lê os PDFs dos extratos

**O que estava a acontecer.** O `erros_proxy.log` do servidor está cheio de
linhas como esta:

```
[2026-08-19 16:12:23] IA falhou definitivamente: Operation timed out after 50009 milliseconds
```

O ficheiro PDF inteiro era enviado à IA numa única chamada. Num extrato de
várias páginas a resposta demora mais de 50 segundos, e o `upload.php`
desligava a ligação a esse ponto. Resultado: falhava tudo, sem importar um
único movimento — e ainda repetia a chamada, gastando o dobro do tempo e do
dinheiro para falhar outra vez.

**O que passa a acontecer.**

1. O texto do PDF é extraído **no teu computador** (biblioteca pdf.js). Já não
   é preciso mandar o ficheiro todo para a IA — vai só o texto, que é dezenas
   de vezes mais leve.
2. O texto é partido em blocos de ~55 linhas, sempre no início de um movimento
   (nunca corta um movimento ao meio). Cada bloco é uma chamada curta à IA,
   de segundos. O tempo limite deixa de ser atingido.
3. Se um bloco falhar, os outros continuam. No fim aparece um aviso a dizer
   quantas partes ficaram por ler, com um botão **"Repetir só as partes em
   falta"**.
4. Se o PDF for digitalizado (uma fotografia, sem texto), cada página é
   convertida em imagem e lida pela IA página a página.
5. Se a biblioteca pdf.js não carregar (internet/CDN em baixo), volta ao
   método antigo — nunca fica pior do que estava.
6. A resposta da IA passou a ser lida com tolerância: se vier cortada a meio,
   aproveitam-se os movimentos que já estão completos em vez de deitar fora
   a resposta inteira.

No servidor (`upload.php`):

- O tempo limite da chamada à IA subiu de **50 → 110 segundos**.
- A repetição automática deixou de acontecer em caso de *timeout* (só serve
  para gastar mais 50 segundos e estourar o limite do PHP). Continua a
  repetir nos erros rápidos do lado da Anthropic (429, 500, 529).
- Quando mesmo assim demora demasiado, a mensagem passa a explicar o que
  fazer em vez de mostrar o erro técnico do cURL.

## 2. Voltar atrás num movimento marcado "sem fatura"

Um movimento marcado como *sem fatura* (salário, imposto, transferência…)
ficava fechado: os botões de anexar fatura desapareciam. O botão "Repor" que
existia não limpava a marca nem gravava no Google — bastava recarregar a
página para o movimento voltar a aparecer como ignorado.

Agora, em cada linha marcada como sem fatura (no **Extrato Bancário** e na
**Conciliação**):

- **Anexar fatura** — reabre o movimento e abre logo o formulário de lançar
  fatura, com o campo para carregar o PDF.
- **Procurar** — reabre e abre a janela de procura entre as faturas já
  registadas.
- **Repor** — só reabre, deixando-o pendente.

A marca (`razao_sem_fatura` / `notas_sem_fatura`) é apagada e a alteração é
gravada no Google, por isso não volta atrás sozinha. Se a lista estiver
filtrada por "Ignorados", o filtro passa a "Todos os estados" para o
movimento não desaparecer do ecrã. O motivo do "sem fatura" passa também a
aparecer na própria linha, e a etiqueta diz "Sem fatura" em vez de "Ign.".

Corrigido pelo caminho: o formulário de lançar fatura escolhia o **mês e o
trimestre errados** (lia 10/06/2026 como 6 de Outubro, à americana).

## 3. Marcas que saltavam de um movimento para outro

**O que corria mal.** Ao reimportar um extrato, os movimentos novos eram
emparelhados com os já gravados por **conta + data + valor + tipo** — sem a
descrição. Para desempatar entre linhas do mesmo dia e do mesmo valor,
comparavam-se os **primeiros 25 caracteres** da descrição. Nas linhas do
género `DÉBITO DIRETO-PAYPAL EUROPE S.-5FZJ2254…` esses 25 caracteres são
iguais em todas: o desempate não desempatava nada e o estado ia parar à
primeira linha da lista.

Consequência: uma marca de "sem fatura" — ou uma **fatura já ligada** — podia
saltar para um movimento diferente, do mesmo dia e do mesmo valor, que
ninguém tinha marcado. Reproduzido em teste: dois débitos de €9,00 a 09/06,
o do Spotify marcado pela equipa e o da Meta pendente; depois de reimportar,
a marca estava na Meta e o Spotify tinha voltado a pendente.

**Regra nova.** Uma linha que já existe **nunca é reescrita**. Por cada grupo
conta+data+valor+tipo conta-se quantas linhas já existem e quantas vêm no
extrato, e só se criam linhas para as que estão a mais. Como nada é
reatribuído, não há estado nenhum para herdar — as linhas antigas ficam
exactamente como estavam, com as tuas marcas e as tuas faturas.

O emparelhamento passou também a comparar as **palavras todas** da descrição
(sem acentos e sem pontuação), em vez dos 25 primeiros caracteres. Serve
apenas para contar quantas linhas são novas e para preencher uma categoria
em falta — nunca para mover estados.

Corrigido pelo caminho: um movimento genuinamente novo num grupo que já
tinha linhas recebia um id repetido (`_1`) e acabava descartado pela rede de
segurança contra ids duplicados. Agora continua a sequência (`_3`, `_4`…).

## 4. Nada é marcado automaticamente

A importação deixou de conciliar sozinha. Antes, um movimento novo cujo
valor batesse certo com uma fatura era logo marcado como **conciliado**, sem
ninguém decidir. Agora **todos os movimentos importados entram como
pendentes** e a sugestão aparece na Conciliação (com a estrela ⭐ e o botão
*Ligar*), para seres tu a confirmar. A mensagem no fim da importação diz
quantos têm fatura sugerida.

O botão **"Re-tentar conciliação"** continua a existir para quem quiser a
ligação em massa — mas só quando é clicado.

## 5. Chave da API e senhas

A chave da Anthropic e as senhas estavam escritas dentro do `upload.php`.
Passaram para um ficheiro à parte, que não vai para o Git:

- `config.local.php` (ao lado do `upload.php`), **ou**
- `../../config/contabilidade.config.php` (fora da pasta pública — mais
  seguro, é onde já está a configuração da base de dados).

Usa o `config.local.exemplo.php` como modelo. **A chave antiga foi exposta:
gera uma nova em console.anthropic.com e apaga a antiga.** Enquanto a chave
não estiver configurada, a IA responde com uma mensagem clara em vez de
falhar sem explicação.

## Como instalar

Envia por FTP para `public_html/contabilidade/`:

- `index.html`
- `upload.php`
- `config.local.php` (criado a partir do exemplo, com a chave nova)

O canto superior esquerdo deve passar a mostrar **v2026.08.19**.
