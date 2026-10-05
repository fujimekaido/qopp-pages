<?php
/* QOPP: приём заявок с форм сайта и пересылка письмом на корпоративную почту (с 05.10.2026).
   Форма шлёт сюда JSON методом POST, скрипт проверяет его и отправляет письмо на $TO.
   В письме Reply-To стоит на адрес заявителя: менеджеру достаточно нажать «Ответить».
   Ничего не сохраняется на диск и не уходит третьим лицам: заявка живёт только в почтовом ящике (серверы в России).
   Работает на хостинге с PHP 7.0+ и включённой функцией mail(). Если хостинг требует отправку через SMTP,
   функцию mail() ниже нужно заменить на SMTP-клиент хостинга; адрес $FROM должен быть ящиком на домене сайта.
   Файл лежит в site/ и правится руками (build.py его не трогает). На GitHub Pages он не исполняется, там форма
   сама предлагает отправить заявку письмом. */

$TO   = 'info@qopp.ru';
$FROM = 'info@qopp.ru';
$HOSTS = array('qopp.ru', 'www.qopp.ru');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($code, $ok, $msg = '') {
    http_response_code($code);
    echo json_encode(array('ok' => $ok, 'error' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}
/* одна строка без управляющих символов: значение безопасно ставить в заголовок письма */
function line($v, $max) {
    $v = is_string($v) ? $v : '';
    $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
    return mb_substr($v, 0, $max, 'UTF-8');
}
function text($v, $max) {
    $v = is_string($v) ? $v : '';
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', str_replace("\r", '', $v)));
    return mb_substr($v, 0, $max, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, false, 'method');

/* заявки принимаем только со страниц своего сайта */
$src = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
$host = strtolower((string)parse_url($src, PHP_URL_HOST));
if (!in_array($host, $HOSTS, true)) out(403, false, 'origin');

$raw = file_get_contents('php://input', false, null, 0, 20000);
$d = json_decode($raw, true);
if (!is_array($d)) out(400, false, 'json');

/* защита от роботов: скрытое поле должно быть пустым, а форму нельзя заполнить быстрее пяти секунд.
   Роботу отвечаем «принято», чтобы он не подбирал обход */
if (line(isset($d['site']) ? $d['site'] : '', 200) !== '' || (int)(isset($d['ms']) ? $d['ms'] : 0) < 5000) out(200, true);

$name  = line(isset($d['name']) ? $d['name'] : '', 100);
$email = line(isset($d['email']) ? $d['email'] : '', 150);
$phone = line(isset($d['phone']) ? $d['phone'] : '', 100);
$who   = line(isset($d['who']) ? $d['who'] : '', 100);
$company = line(isset($d['company']) ? $d['company'] : '', 150);
$city  = line(isset($d['city']) ? $d['city'] : '', 100);
$inn   = line(isset($d['inn']) ? $d['inn'] : '', 20);
$cats  = line(isset($d['cats']) ? $d['cats'] : '', 300);
$detail = text(isset($d['detail']) ? $d['detail'] : '', 3000);
$page  = line(isset($d['page']) ? $d['page'] : '', 200);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) out(422, false, 'fields');
if (empty($d['ok'])) out(422, false, 'consent');

$L = array('Заявка на сотрудничество с сайта QOPP', '');
if ($who !== '')     $L[] = 'Кто: ' . $who;
if ($detail !== '')  $L[] = 'Подробнее: ' . $detail;
if ($cats !== '')    $L[] = 'Интересует: ' . $cats;
if ($company !== '') $L[] = 'Компания: ' . $company . ($city !== '' ? ', ' . $city : '') . ($inn !== '' ? ', ИНН ' . $inn : '');
$L[] = '';
$L[] = 'Имя: ' . $name;
$L[] = 'E-mail: ' . $email;
if ($phone !== '')   $L[] = 'Телефон или мессенджер: ' . $phone;
$L[] = '';
$L[] = 'Согласие на обработку персональных данных: дано, ' . date('d.m.Y H:i') . ' (время сервера)';
if ($page !== '')    $L[] = 'Страница: ' . $page;
$L[] = '';
$L[] = 'Чтобы ответить заявителю, нажмите «Ответить».';

$subject = 'Сотрудничество: ' . ($who !== '' ? $who : 'заявка') . ($company !== '' ? ', ' . $company : '');
$enc = function ($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; };
$headers = array(
    'From: ' . $enc('Сайт QOPP') . ' <' . $FROM . '>',
    'Reply-To: ' . $enc($name) . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
);
$sent = @mail($TO, $enc($subject), implode("\r\n", $L), implode("\r\n", $headers), '-f' . $FROM);
if (!$sent) out(500, false, 'mail');
out(200, true);
