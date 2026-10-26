#!/usr/bin/env bash
# Аудит безпеки застосунку (практикум №8): SQL-ін'єкція, XSS, CSRF, дії через GET, валідація, помилки.
#
# Використання (сервер має працювати: php -S localhost:8000):
#   BASE=http://localhost:8000 ./tests/audit.sh
# Необов'язково, якщо API лежить деінде:
#   API=http://localhost:8000/api.php BASE=http://localhost:8000 ./tests/audit.sh
#
# Скрипт створює тимчасові товари з SKU AUDIT* і наприкінці видаляє їх.
set -u

BASE="${BASE:-http://localhost:8000}"
API="${API:-$BASE/api.php}"
JAR="$(mktemp)"
TMP="$(mktemp -d)"
trap 'rm -rf "$JAR" "$TMP"' EXIT

PASS=0; FAIL=0; SKIP=0
ok()   { echo "[PASS] $1"; PASS=$((PASS+1)); }
bad()  { echo "[FAIL] $1"; FAIL=$((FAIL+1)); if [ -n "${2:-}" ]; then echo "       -> $2"; fi; }
skip() { echo "[SKIP] $1"; SKIP=$((SKIP+1)); }

# ---------------------------------------------------------------- допоміжні функції

cat > "$TMP/xss.py" << 'PY'
import sys
from html.parser import HTMLParser

class Checker(HTMLParser):
    def __init__(self):
        super().__init__()
        self.found = []
    def handle_starttag(self, tag, attrs):
        d = dict(attrs)
        if tag == 'img':
            self.found.append("введено тег <img> (payload став HTML-елементом)")
        if tag == 'script' and 'src' not in d:
            self.found.append("з'явився inline <script>")
        for k, v in attrs:
            if k.lower().startswith('on') and v and ('XSSMARK' in v or 'alert' in v):
                self.found.append("в атрибуті %s після розкодування HTML-сутностей: %s" % (k, v))

c = Checker()
c.feed(sys.stdin.read())
for f in c.found:
    print(f)
sys.exit(1 if c.found else 0)
PY

cat > "$TMP/ids.py" << 'PY'
import re, sys
# Використання: ids.py PREFIX_OR_SKU [--prefix]; читає HTML каталогу зі stdin, друкує id рядків.
want = sys.argv[1]
prefix = len(sys.argv) > 2
html = sys.stdin.read()
for row in re.findall(r"<tr.*?</tr>", html, re.S):
    m_id = re.search(r"edit\.php\?id=(\d+)", row)
    m_sku = re.search(r'<td class="stock">([A-Z0-9-]+)</td>', row)
    if not (m_id and m_sku):
        continue
    sku = m_sku.group(1)
    if (prefix and sku.startswith(want)) or (not prefix and sku == want):
        print(m_id.group(1))
PY

fetch_token() {
    curl -s -b "$JAR" -c "$JAR" "$BASE/add.php" \
      | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/'
}
TOKEN="$(fetch_token)"

id_by_sku()  { curl -s "$BASE/index.php" | python3 "$TMP/ids.py" "$1" | head -1; }
exists_sku() { [ -n "$(id_by_sku "$1")" ]; }

# POST на add.php з дійсним токеном (якщо застосунок його видає). Друкує HTTP-код, тіло — у $TMP/body.
post_add() {  # name price sku stock
    local args=(--data-urlencode "name=$1" --data-urlencode "price=$2" --data-urlencode "sku=$3" --data-urlencode "stock=$4")
    if [ -n "$TOKEN" ]; then args+=(--data-urlencode "csrf_token=$TOKEN"); fi
    curl -s -b "$JAR" -c "$JAR" -o "$TMP/body" -w '%{http_code}' -X POST "${args[@]}" "$BASE/add.php"
}

make_victim() {  # sku -> id
    post_add "Victim $1" 10 "$1" 5 >/dev/null
    local id
    id="$(id_by_sku "$1")"
    if [ -z "$id" ]; then
        echo "[FAIL] не вдалося створити тестовий товар $1 — результати нижче недостовірні" >&2
        FAIL=$((FAIL+1))
    fi
    echo "$id"
}

RAW_ERR='SQLSTATE|PDOException|Stack trace|Fatal error|Warning:|Notice:|Deprecated:|Array to string'

echo "== Адреса застосунку: $BASE (API: $API)"
if [ -z "$TOKEN" ]; then
    echo "== CSRF-токен у формах не знайдено — застосунок без CSRF-захисту"
fi
echo

# ================================================================ 1. SQL-ін'єкції
echo "--- Крок 1. SQL-ін'єкції (пошук за sku) ---"
for payload in "' OR '1'='1" "' UNION SELECT 1,2,3,4,5-- -" "'; DROP TABLE products;-- "; do
    body="$(curl -s -G --data-urlencode "sku=$payload" "$BASE/index.php")"
    if echo "$body" | grep -q "Знайдено:"; then
        bad "index.php?sku=  payload: $payload" "запит повернув товар — ін'єкція спрацювала"
    elif echo "$body" | grep -q "не знайдено"; then
        ok "index.php?sku=  payload: $payload  -> «не знайдено»"
    else
        bad "index.php?sku=  payload: $payload" "неочікувана відповідь сторінки"
    fi
done
if exists_sku "MAT-003"; then ok "таблиця products ціла після спроби DROP TABLE"; else bad "таблиця products пошкоджена або порожня"; fi

code="$(curl -s -o /dev/null -w '%{http_code}' -G --data-urlencode "resource=products" --data-urlencode "sku=NOPE-000" "$API")"
if [ "$code" = "404" ]; then
    code="$(curl -s -o "$TMP/body" -w '%{http_code}' -G --data-urlencode "resource=products" --data-urlencode "sku=' OR '1'='1" "$API")"
    if [ "$code" = "404" ]; then ok "API ?sku=  payload: ' OR '1'='1  -> 404"; else bad "API ?sku= payload ' OR '1'='1" "HTTP $code"; fi
else
    skip "API без пошуку за sku"
fi
echo

# ================================================================ 2. XSS
echo "--- Крок 2. XSS (поле name товару) ---"
declare_payloads() {
    PAYLOADS=("');alert('XSSMARK');//" "<img src=x onerror=alert('XSSMARK')>" "<script>alert('XSSMARK')</script>")
    PSKUS=("AUDITXA-1" "AUDITXB-2" "AUDITXC-3")
}
declare_payloads
for i in 0 1 2; do
    code="$(post_add "${PAYLOADS[$i]}" 10 "${PSKUS[$i]}" 1)"
    if [ "$code" != "302" ]; then bad "створення товару з payload ${PSKUS[$i]}" "HTTP $code"; fi
done
out="$(curl -s "$BASE/index.php" | python3 "$TMP/xss.py")"
if [ $? -eq 0 ]; then ok "index.php: payload-и у назві не стають кодом (HTML, атрибути, inline-скрипти)"; else bad "index.php: XSS через назву товару" "$out"; fi
for i in 0 1 2; do
    pid="$(id_by_sku "${PSKUS[$i]}")"
    if [ -n "$pid" ]; then
        out="$(curl -s "$BASE/edit.php?id=$pid" | python3 "$TMP/xss.py")"
        if [ $? -eq 0 ]; then ok "edit.php?id=$pid: payload ${PSKUS[$i]} екрановано"; else bad "edit.php?id=$pid: XSS" "$out"; fi
    fi
done
echo

# ================================================================ 3. CSRF
echo "--- Крок 3. CSRF ---"
V="AUDITCA-1"; ID="$(make_victim $V)"
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "id=$ID" "$BASE/delete.php")"
if exists_sku "$V"; then ok "delete.php без токена і без сесії -> $code, товар на місці"; else bad "delete.php без токена і без сесії" "HTTP $code — товар видалено сторонньою формою"; fi

V="AUDITCB-2"; ID="$(make_victim $V)"
code="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -d "id=$ID&csrf_token=WRONG" "$BASE/delete.php")"
if exists_sku "$V"; then ok "delete.php з хибним токеном -> $code, товар на місці"; else bad "delete.php з хибним токеном" "HTTP $code — товар видалено"; fi

V="AUDITCC-3"; ID="$(make_victim $V)"
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "id=$ID&csrf_token=" "$BASE/delete.php")"
if exists_sku "$V"; then ok "delete.php з порожнім токеном без сесії -> $code, товар на місці"; else bad "delete.php з порожнім токеном без сесії" "HTTP $code — товар видалено"; fi

code="$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "name=NoToken" -d "price=1&sku=AUDITNA-1&stock=1" "$BASE/add.php")"
if exists_sku "AUDITNA-1"; then bad "add.php без токена" "HTTP $code — товар створено сторонньою формою"; else ok "add.php без токена -> $code, товар не створено"; fi

V="AUDITCD-4"; ID="$(make_victim $V)"
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "id=$ID&name=HACKED8&price=1&sku=$V&stock=1" "$BASE/edit.php")"
if curl -s "$BASE/index.php" | grep -q "HACKED8"; then bad "edit.php без токена" "HTTP $code — товар змінено сторонньою формою"; else ok "edit.php без токена -> $code, товар не змінено"; fi

V="AUDITCE-5"; ID="$(make_victim $V)"
rcode="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/restock.php?id=$ID")"
if [ "$rcode" = "404" ]; then
    skip "форма поповнення залишку (restock.php) відсутня"
else
    code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "id=$ID&quantity=5" "$BASE/restock.php")"
    if [ "$code" = "403" ]; then ok "restock.php без токена -> 403"; else bad "restock.php без токена" "HTTP $code"; fi
    code="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -d "id=$ID&quantity=5&csrf_token=WRONG" "$BASE/restock.php")"
    if [ "$code" = "403" ]; then ok "restock.php зі старим/хибним токеном -> 403"; else bad "restock.php з хибним токеном" "HTTP $code"; fi
    code="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST --data-urlencode "id=$ID" -d "quantity=5" --data-urlencode "csrf_token=$TOKEN" "$BASE/restock.php")"
    if [ "$code" = "302" ]; then ok "restock.php з дійсним токеном -> 302 (поповнення працює)"; else bad "restock.php з дійсним токеном" "HTTP $code"; fi
fi

code="$(curl -s -o "$TMP/body" -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d '{"name":"ApiNoToken","price":1,"sku":"AUDITAA-1","stock":1}' "$API?resource=products")"
if [ "$code" = "403" ]; then ok "API POST без токена -> 403"; else bad "API POST без токена" "HTTP $code — API приймає запис без CSRF-токена"; fi

code="$(curl -s -o "$TMP/body" -w '%{http_code}' -X POST -d "name=AjaxNoToken&price=1&sku=AUDITAB-2&stock=1" "$BASE/api_add.php")"
if [ "$code" = "403" ]; then ok "api_add.php (AJAX-форма) без токена -> 403"; else bad "api_add.php без токена" "HTTP $code — AJAX-ендпоінт приймає запис без CSRF-токена"; fi

tcode="$(curl -s -o "$TMP/tok" -w '%{http_code}' -b "$JAR" -c "$JAR" "$API?resource=csrf")"
if [ "$tcode" = "200" ]; then
    atok="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["data"]["csrf_token"])' "$TMP/tok")"
    code="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: $atok" \
            -d '{"name":"ApiWithToken","price":1,"sku":"AUDITAC-3","stock":1}' "$API?resource=products")"
    if [ "$code" = "201" ]; then ok "API POST з дійсним X-CSRF-Token -> 201"; else bad "API POST з дійсним токеном" "HTTP $code"; fi
else
    skip "API без видачі CSRF-токена"
fi
echo

# ================================================================ API (практикум №6)
echo "--- API з практикуму №6 (restock — доменна дія варіанта) ---"
ATOK=""
tcode="$(curl -s -o "$TMP/atok" -w '%{http_code}' -b "$JAR" -c "$JAR" "$API?resource=csrf")"
if [ "$tcode" = "200" ]; then
    ATOK="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["data"]["csrf_token"])' "$TMP/atok")"
fi

api_post() {  # url json_тіло use_token(yes|no) -> HTTP-код; тіло відповіді у $TMP/abody
    local args=(-g -s -o "$TMP/abody" -w '%{http_code}' -b "$JAR" -X POST -H 'Content-Type: application/json' -d "$2")
    if [ "$3" = "yes" ] && [ -n "$ATOK" ]; then args+=(-H "X-CSRF-Token: $ATOK"); fi
    curl "${args[@]}" "$1"
}
api_stock() {  # id -> поточний залишок
    curl -s "$API?resource=products&id=$1" | python3 -c 'import sys,json
try:
    print(json.load(sys.stdin)["data"]["stock"])
except Exception:
    print("")'
}
RESTOCK="$API?resource=products&action=restock"

V="AUDITQA-1"; ID="$(make_victim $V)"
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "id=$ID&quantity=1000" "$RESTOCK")"
stock="$(api_stock "$ID")"
if [ "$code" = "403" ] && [ "$stock" = "5" ]; then
    ok "API restock «з чужої форми» (urlencoded, без cookie і токена) -> 403, залишок не змінено"
else
    bad "API restock «з чужої форми» без токена" "HTTP $code, залишок тепер $stock (було 5) — залишок змінено стороннім сайтом"
fi

if [ -n "$ATOK" ]; then
    code="$(api_post "$RESTOCK" "{\"id\":$ID,\"quantity\":5}" yes)"; stock="$(api_stock "$ID")"
    if [ "$code" = "200" ] && [ "$stock" = "10" ]; then ok "API restock з дійсним токеном -> 200, залишок 5 -> 10"; else bad "API restock з дійсним токеном" "HTTP $code, залишок $stock"; fi
else
    skip "API restock з токеном (API не видає CSRF-токенів)"
fi

code="$(api_post "$RESTOCK" "{\"id\":$ID,\"quantity\":99999999999}" yes)"
if [ "$code" = "400" ]; then ok "API restock: quantity 99999999999 -> 400 (без переповнення INT)"; else bad "API restock: quantity 99999999999" "HTTP $code (очікувалось 400)"; fi
code="$(api_post "$RESTOCK" "{\"id\":$ID,\"quantity\":0}" yes)"
if [ "$code" = "400" ]; then ok "API restock: quantity 0 -> 400"; else bad "API restock: quantity 0" "HTTP $code"; fi
code="$(api_post "$RESTOCK" '{"id":999999,"quantity":1}' yes)"
if [ "$code" = "404" ]; then ok "API restock: неіснуючий id -> 404"; else bad "API restock: неіснуючий id" "HTTP $code"; fi

code="$(api_post "$API?resource=products" '{"name":["x"],"price":10,"sku":"AUDITTA-1","stock":1}' yes)"
if [ "$code" = "400" ] && ! exists_sku "AUDITTA-1"; then
    ok "API POST: name як масив -> 400, нічого не записано"
else
    bad "API POST: name як масив" "HTTP $code, у базу записано товар із назвою «$(python3 -c 'import json,sys
try: print(json.load(open(sys.argv[1]))["data"]["name"])
except Exception: print("?")' "$TMP/abody")»"
fi

LONGJ="$(python3 -c 'print("A"*300)')"
code="$(api_post "$API?resource=products" "{\"name\":\"$LONGJ\",\"price\":10,\"sku\":\"AUDITTB-2\",\"stock\":1}" yes)"
if [ "$code" = "400" ]; then ok "API POST: назва 300 символів -> 400 (помилка валідації)"; else bad "API POST: назва 300 символів" "HTTP $code (очікувалось 400)"; fi

code="$(api_post "$API?resource=products" '5' yes)"
if [ "$code" = "400" ]; then ok "API POST: тіло — JSON-скаляр -> 400"; else bad "API POST: тіло — JSON-скаляр" "HTTP $code (очікувалось 400)"; fi

code="$(api_post "$API?resource=products&action[]=x" '{}' yes)"
if [ "$code" = "400" ] && ! grep -q "Array" "$TMP/abody"; then ok "API POST: action[]=x -> 400 без відлуння «Array»"; else bad "API POST: action[]=x" "HTTP $code, відповідь: $(cut -c1-80 "$TMP/abody")"; fi

code="$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$API?resource=products&id=$ID")"
if [ "$code" = "405" ]; then ok "API DELETE -> 405 (метод не підтримується контрактом)"; else bad "API DELETE" "HTTP $code (очікувалось 405)"; fi

if curl -sI "$API?resource=products" | grep -qi '^x-content-type-options: *nosniff'; then
    ok "API: заголовок X-Content-Type-Options: nosniff"
else
    bad "API: заголовок X-Content-Type-Options" "відсутній — браузер може «вгадувати» тип вмісту"
fi
echo

# ================================================================ 4. Дії через GET
echo "--- Крок 4/5. Видалення за GET-посиланням ---"
V="AUDITGA-1"; ID="$(make_victim $V)"
code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/delete.php?id=$ID")"
if exists_sku "$V"; then ok "GET delete.php?id=$ID -> HTTP $code, товар не видалено"; else bad "GET delete.php?id=$ID" "товар видалено за звичайним посиланням"; fi
echo

# ================================================================ 5. Валідація і помилки
echo "--- Крок 4/6. Валідація на сервері та «сирі» помилки ---"
code="$(post_add "" "" "" "")"
if [ "$code" = "200" ] && grep -q 'class="error"' "$TMP/body"; then ok "порожні поля відхилено на сервері (HTTP 200 + повідомлення)"; else bad "порожні поля" "HTTP $code"; fi

code="$(post_add "NegPrice" "-5" "AUDITVA-1" 1)"
if exists_sku "AUDITVA-1"; then bad "від'ємна ціна" "товар записано"; else ok "від'ємна ціна відхилена (HTTP $code), запису немає"; fi

check_bad_input() {  # опис name price sku stock
    local desc="$1" skucheck="$4"
    local code
    code="$(post_add "$2" "$3" "$4" "$5")"
    if [ "$code" = "500" ] || grep -qE "$RAW_ERR" "$TMP/body"; then
        bad "$desc" "HTTP $code, у відповіді «сирий» текст помилки: $(grep -oE "$RAW_ERR" "$TMP/body" | head -1)"
    elif exists_sku "$skucheck"; then
        bad "$desc" "некоректні дані записано в базу"
    else
        ok "$desc -> HTTP $code, помилки валідації без витоку деталей"
    fi
}
LONG="$(python3 -c 'print("A"*300)')"
check_bad_input "назва 300 символів"          "$LONG"     10             "AUDITLA-1" 1
check_bad_input "ціна 1e12 (поза DECIMAL)"     "BigPrice"  1000000000000  "AUDITLB-2" 1
check_bad_input "залишок 99999999999 (поза INT)" "BigStock" 10            "AUDITLC-3" 99999999999
check_bad_input "SKU довший за 20 символів"    "LongSku"   10             "ABCDEFGHIJKLMNOPQRSTUVWXYZ-123456" 1

code="$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/body" -w '%{http_code}' -X POST \
        --data-binary "name=%FF%FE&price=10&sku=AUDITUA-1&stock=1&csrf_token=$TOKEN" "$BASE/add.php")"
if [ "$code" = "500" ] || grep -qE "$RAW_ERR" "$TMP/body"; then
    bad "назва з некоректними байтами UTF-8" "HTTP $code, у відповіді: $(grep -oE "$RAW_ERR" "$TMP/body" | head -1)"
elif exists_sku "AUDITUA-1"; then
    bad "назва з некоректними байтами UTF-8" "некоректні дані записано в базу"
else
    ok "назва з некоректними байтами UTF-8 -> HTTP $code, відхилено без витоку деталей"
fi

body="$(curl -s -g "$BASE/index.php?sku[]=x")"
if echo "$body" | grep -qE "$RAW_ERR"; then
    bad "index.php?sku[]=x (параметр-масив)" "на сторінці видно: $(echo "$body" | grep -oE "$RAW_ERR" | head -1)"
else
    ok "index.php?sku[]=x не показує технічних помилок PHP"
fi
echo

# ================================================================ прибирання
for tid in $(curl -s "$BASE/index.php" | python3 "$TMP/ids.py" "AUDIT" --prefix); do
    args=(-d "id=$tid")
    if [ -n "$TOKEN" ]; then args+=(--data-urlencode "csrf_token=$TOKEN"); fi
    curl -s -o /dev/null -b "$JAR" -X POST "${args[@]}" "$BASE/delete.php"
done
left="$(curl -s "$BASE/index.php" | python3 "$TMP/ids.py" "AUDIT" --prefix | wc -l | tr -d ' ')"
echo "Прибирання: тимчасових товарів AUDIT* залишилось: $left"
echo
echo "ПІДСУМОК: PASS=$PASS  FAIL=$FAIL  SKIP=$SKIP"
[ "$FAIL" -eq 0 ]
