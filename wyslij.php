<?php
/*
 * NA GOTOWO - wysyłka formularzy ze strony: zapytanie o wycenę i zgłoszenie zmiany w trakcie realizacji
 * Plik wgraj na serwer obok strony (index.html). Wymaga hostingu z PHP 7.4 lub nowszym.
 * Zgłoszenia nie są zapisywane na serwerze, trafiają tylko mailem na adres z ustawień.
 */

/* ===== USTAWIENIA ===== */

// Adres, na który przychodzą zgłoszenia.
const ODBIORCA = 'kontakt@nagotowobialystok.pl';

// Adres nadawcy. Musi być w domenie podpiętej do hostingu OVH (nagotowobialystok.pl),
// inaczej OVH odrzuci wysyłkę albo maile trafią do spamu.
const NADAWCA = 'kontakt@nagotowobialystok.pl';
const NADAWCA_NAZWA = 'Formularz NA GOTOWO';

// SMTP (opcjonalnie). Zostaw SMTP_HOST pusty, żeby wysyłać funkcją mail() hostingu OVH
// (darmowy hosting: do 10 maili na godzinę, nadmiar czeka w kolejce i wychodzi później).
// Wysyłka przez skrzynkę Zimbra: host 'smtp.mail.ovh.net', port 465, login NADAWCA i hasło tej skrzynki.
const SMTP_HOST = '';
const SMTP_PORT = 465;          // 465 = SSL, 587 = STARTTLS
const SMTP_USER = '';
const SMTP_PASS = '';

// Czy wysyłać klientowi mailowe potwierdzenie z kopią zgłoszenia (true / false).
const POTWIERDZENIE = true;

// Limity
const MAX_ZDJEC = 10;     // zdjęcia w zgłoszeniu zmiany
const MAX_RZUTOW = 3;     // pliki z rzutem w zapytaniu o wycenę
const MAX_ROZMIAR = 6 * 1024 * 1024;    // łącznie, w bajtach; cały mail musi się zmieścić w limicie 10 MB poczty OVH

/* ===== KONIEC USTAWIEŃ ===== */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function odpowiedz($ok, $blad = '', $kod = 200) {
    http_response_code($kod);
    echo json_encode(['ok' => $ok, 'blad' => $blad], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    odpowiedz(false, 'Nieprawidłowe żądanie.', 405);
}

// Pułapki na boty: ukryte pole i zbyt szybkie wysłanie. Botu nie informujemy o odrzuceniu.
if (!empty($_POST['strona_www'])) odpowiedz(true);
$start = (int)($_POST['t'] ?? 0);
if ($start > 0 && (round(microtime(true) * 1000) - $start) < 3000) odpowiedz(true);

function pole($nazwa, $max) {
    $v = trim((string)($_POST[$nazwa] ?? ''));
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    return mb_substr($v, 0, $max, 'UTF-8');
}
function jednaLinia($v) {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $v));
}

function wybor($nazwa, array $dozwolone) {
    $v = (string)($_POST[$nazwa] ?? '');
    return in_array($v, $dozwolone, true) ? $v : '';
}
function lista($nazwa, array $dozwolone) {
    $v = $_POST[$nazwa] ?? [];
    if (!is_array($v)) $v = [$v];
    return array_values(array_intersect($dozwolone, array_map('strval', $v)));
}
function pliki($pole, array $dozwolone, $max, $prefiks, $opis) {
    $wynik = [];
    if (empty($_FILES[$pole]) || !is_array($_FILES[$pole]['name'])) return $wynik;
    $f = $_FILES[$pole];
    $ile = count($f['name']);
    if ($ile > $max) odpowiedz(false, 'Możesz dołączyć najwyżej ' . $max . ' plików.', 422);
    $suma = 0;
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    for ($i = 0; $i < $ile; $i++) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ($f['error'][$i] !== UPLOAD_ERR_OK) odpowiedz(false, 'Nie udało się przesłać plików. Spróbuj z mniejszymi lub z mniejszą liczbą plików.', 422);
        $tmp = $f['tmp_name'][$i];
        $suma += filesize($tmp);
        if ($suma > MAX_ROZMIAR) odpowiedz(false, 'Pliki są razem za duże. Usuń część z nich.', 422);
        $typ = $finfo ? finfo_file($finfo, $tmp) : ($f['type'][$i] ?? '');
        if (!isset($dozwolone[$typ])) odpowiedz(false, 'Można dołączać tylko ' . $opis . '.', 422);
        $wynik[] = ['nazwa' => $prefiks . '-' . ($i + 1) . '.' . $dozwolone[$typ], 'typ' => $typ, 'dane' => file_get_contents($tmp)];
    }
    if ($finfo) finfo_close($finfo);
    return $wynik;
}
$ZDJECIA = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'];

$formularz = (($_POST['formularz'] ?? '') === 'wycena') ? 'wycena' : 'zmiana';

$imie    = jednaLinia(pole('imie', 120));
$telefon = jednaLinia(pole('telefon', 40));
$email   = jednaLinia(pole('email', 160));
$rodo    = pole('rodo', 400);

$bledy = [];
if ($imie === '') $bledy[] = 'imię i nazwisko';
if (strlen(preg_replace('/\D/', '', $telefon)) < 9) $bledy[] = 'telefon';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $bledy[] = 'e-mail';

date_default_timezone_set('Europe/Warsaw');

if ($formularz === 'zmiana') {
    /* ----- Zgłoszenie zmiany w trakcie realizacji ----- */
    $lokal    = jednaLinia(pole('lokal', 200));
    $remont   = pole('remont', 5000);
    $stolarka = pole('stolarka', 5000);
    $oswiadczenie = pole('oswiadczenie', 400);
    if ($lokal === '') $bledy[] = 'inwestycja i numer mieszkania';
    if ($remont === '' && $stolarka === '') $bledy[] = 'opis zmiany';
    if ($oswiadczenie === '') $bledy[] = 'oświadczenie';
    if ($rodo === '') $bledy[] = 'potwierdzenie informacji o danych osobowych';
    if ($bledy) odpowiedz(false, 'Uzupełnij: ' . implode(', ', $bledy) . '.', 422);

    $zalaczniki = pliki('zdjecia', $ZDJECIA, MAX_ZDJEC, 'zdjecie', 'zdjęcia (JPG, PNG, WebP, HEIC)');
    $tytul = 'Zgłoszenie zmiany w wykończeniu mieszkania';
    $temat = 'Zgłoszenie zmiany: ' . $lokal;
    $wiersze = [
        'Data zgłoszenia' => date('d.m.Y, H:i'),
        'Imię i nazwisko' => $imie,
        'Telefon' => $telefon,
        'E-mail' => $email,
        'Inwestycja, budynek, mieszkanie' => $lokal,
        'Remont i wykończenie mieszkania' => $remont !== '' ? $remont : '-',
        'Stolarka meblowa' => $stolarka !== '' ? $stolarka : '-',
        'Załącznik zdjęciowy' => $zalaczniki ? 'tak (' . count($zalaczniki) . ')' : 'nie',
        'Oświadczenie' => $oswiadczenie,
        'Informacja RODO' => $rodo,
    ];
    $kopiaTemat = 'Otrzymaliśmy Twoje zgłoszenie zmiany';
    $kopiaAkapity = [
        'dziękujemy za zgłoszenie zmiany w wykończeniu mieszkania. Sprawdzimy możliwość jej wykonania i przygotujemy wycenę, którą prześlemy Ci do akceptacji.',
        '**Pamiętaj, że zmiana trafi do realizacji dopiero po akceptacji wyceny, płatności i podpisaniu dokumentu przez obie strony.**',
        'Poniżej kopia Twojego zgłoszenia.',
    ];
} else {
    /* ----- Zapytanie o wycenę wykończenia ----- */
    $kiedy      = wybor('kiedy', ['Rano', 'Po południu', 'Wieczorem', 'Obojętnie']);
    $deweloper  = jednaLinia(pole('deweloper', 160));
    $inwestycja = jednaLinia(pole('inwestycja', 160));
    $ulica      = jednaLinia(pole('ulica', 200));
    $numer      = jednaLinia(pole('numer', 60));
    $pietro     = jednaLinia(pole('pietro', 20));
    $metrazTxt  = str_replace(',', '.', jednaLinia(pole('metraz', 12)));
    $metraz     = is_numeric($metrazTxt) ? (float)$metrazTxt : 0;
    $pokoje     = wybor('pokoje', ['1', '2', '3', '4', '5 i więcej']);
    $lazienki   = wybor('lazienki', ['1', '2', '3 i więcej']);
    $wc         = wybor('wc', ['tak']) === 'tak' ? 'tak' : 'nie';
    $dwupoziom  = wybor('dwupoziom', ['Tak', 'Nie']);
    $status     = wybor('status', ['Mieszkanie kupione', 'Umowa deweloperska', 'Dopiero wybieram']);
    $klucze     = jednaLinia(pole('klucze', 60));
    $gotowe     = jednaLinia(pole('gotowe', 60));
    $pakiet     = wybor('pakiet', ['Smart', 'Standard', 'Premium', 'Jeszcze nie wiem']);
    $dodatki    = lista('dodatki', ['Dodatkowe zabudowy stolarskie', 'Zmiana materiałów wykończeniowych', 'Inne płytki, panele, armatura lub oświetlenie', 'Przeróbki elektryczne i hydrauliczne', 'Detale dekoracyjne, np. sztukateria, lamele']);
    $agd        = wybor('agd', ['W ramach pakietu', 'Kupuję własne']);
    $uwagi      = pole('uwagi', 5000);
    $zrodlo     = wybor('zrodlo', ['Facebook', 'Instagram', 'Google', 'Polecenie', 'Deweloper', 'Inne']);

    if ($deweloper === '') $bledy[] = 'deweloper';
    if ($inwestycja === '') $bledy[] = 'inwestycja lub osiedle';
    if ($ulica === '') $bledy[] = 'ulica i miejscowość';
    if ($metraz < 10 || $metraz > 1000) $bledy[] = 'metraż';
    if ($pokoje === '') $bledy[] = 'liczba pokoi';
    if ($lazienki === '') $bledy[] = 'liczba łazienek';
    if ($dwupoziom === '') $bledy[] = 'czy mieszkanie jest dwupoziomowe';
    if ($status === '') $bledy[] = 'status zakupu';
    if ($klucze === '') $bledy[] = 'termin odbioru kluczy';
    if ($pakiet === '') $bledy[] = 'pakiet';
    if ($rodo === '') $bledy[] = 'potwierdzenie informacji o danych osobowych';
    if ($bledy) odpowiedz(false, 'Uzupełnij: ' . implode(', ', $bledy) . '.', 422);

    $zalaczniki = pliki('rzut', $ZDJECIA + ['application/pdf' => 'pdf'], MAX_RZUTOW, 'rzut', 'zdjęcia lub pliki PDF');
    $m2 = rtrim(rtrim(number_format($metraz, 2, ',', ''), '0'), ',');
    $tytul = 'Zapytanie o wycenę wykończenia';
    $temat = 'Zapytanie o wycenę: ' . $inwestycja . ', ' . $m2 . ' m², ' . $pakiet;
    $wiersze = [
        'Data zapytania' => date('d.m.Y, H:i'),
        'Imię i nazwisko' => $imie,
        'Telefon' => $telefon,
        'E-mail' => $email,
        'Kiedy dzwonić' => $kiedy ?: '-',
        'Deweloper' => $deweloper,
        'Inwestycja / osiedle' => $inwestycja,
        'Ulica i miejscowość' => $ulica,
        'Budynek i numer mieszkania' => $numer ?: '-',
        'Piętro' => $pietro !== '' ? $pietro : '-',
        'Metraż' => $m2 . ' m²',
        'Liczba pokoi' => $pokoje,
        'Liczba łazienek' => $lazienki . ($wc === 'tak' ? ', osobne WC' : ''),
        'Mieszkanie dwupoziomowe' => $dwupoziom,
        'Rzut mieszkania' => $zalaczniki ? 'w załączniku (' . count($zalaczniki) . ')' : 'brak',
        'Status zakupu' => $status,
        'Odbiór kluczy od dewelopera' => $klucze,
        'Mieszkanie gotowe na' => $gotowe !== '' ? $gotowe : '-',
        'Pakiet' => $pakiet,
        'Do dodania' => $dodatki ? implode("\n", $dodatki) : '-',
        'AGD' => $agd ?: '-',
        'Uwagi' => $uwagi !== '' ? $uwagi : '-',
        'Skąd o nas wie' => $zrodlo ?: '-',
        'Informacja RODO' => $rodo,
    ];
    $kopiaTemat = 'Otrzymaliśmy Twoje zapytanie o wycenę';
    $kopiaAkapity = [
        'dziękujemy za zapytanie o wycenę wykończenia mieszkania. Odezwiemy się, żeby omówić szczegóły i przygotować wycenę.',
        'Poniżej kopia Twojego zapytania.',
    ];
}

/* ===== Treść maila ===== */
$h = function ($s) { return nl2br(htmlspecialchars($s, ENT_QUOTES, 'UTF-8')); };
$html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#102416">'
      . '<h2 style="font-size:18px;margin:0 0 12px">' . $tytul . '</h2>'
      . '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;max-width:720px">';
$tekst = $tytul . "\n\n";
foreach ($wiersze as $k => $v) {
    $html .= '<tr><td style="border:1px solid #ddd;background:#f6f4ee;font-weight:bold;vertical-align:top;width:220px">'
           . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</td><td style="border:1px solid #ddd;vertical-align:top">' . $h($v) . '</td></tr>';
    $tekst .= $k . ":\n" . $v . "\n\n";
}
$html .= '</table></div>';

/* ===== Budowa wiadomości MIME ===== */
function naglowekUtf8($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; }

function zbudujWiadomosc($replyNazwa, $replyEmail, $tekst, $html, $zalaczniki) {
    $granica = 'ng_' . bin2hex(random_bytes(12));
    $granicaAlt = 'alt_' . bin2hex(random_bytes(12));
    $domena = substr(strrchr(NADAWCA, '@'), 1) ?: 'localhost';
    $naglowki = [
        'From: ' . naglowekUtf8(NADAWCA_NAZWA) . ' <' . NADAWCA . '>',
        'Reply-To: ' . naglowekUtf8($replyNazwa) . ' <' . $replyEmail . '>',
        'MIME-Version: 1.0',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domena . '>',
        'Content-Type: multipart/mixed; boundary="' . $granica . '"',
    ];
    $tresc  = "--$granica\r\n";
    $tresc .= "Content-Type: multipart/alternative; boundary=\"$granicaAlt\"\r\n\r\n";
    $tresc .= "--$granicaAlt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $tresc .= chunk_split(base64_encode($tekst)) . "\r\n";
    $tresc .= "--$granicaAlt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $tresc .= chunk_split(base64_encode($html)) . "\r\n";
    $tresc .= "--$granicaAlt--\r\n";
    foreach ($zalaczniki as $z) {
        $tresc .= "--$granica\r\n";
        $tresc .= 'Content-Type: ' . $z['typ'] . '; name="' . $z['nazwa'] . "\"\r\n";
        $tresc .= "Content-Transfer-Encoding: base64\r\n";
        $tresc .= 'Content-Disposition: attachment; filename="' . $z['nazwa'] . "\"\r\n\r\n";
        $tresc .= chunk_split(base64_encode($z['dane'])) . "\r\n";
    }
    $tresc .= "--$granica--\r\n";
    return [$naglowki, $tresc];
}

/* ===== Wysyłka ===== */
function smtpWyslij($do, $temat, $naglowki, $tresc) {
    $ssl = (SMTP_PORT == 465);
    $gniazdo = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 20);
    if (!$gniazdo) return false;
    stream_set_timeout($gniazdo, 20);
    $czytaj = function () use ($gniazdo) {
        $odp = '';
        while (($linia = fgets($gniazdo, 515)) !== false) {
            $odp .= $linia;
            if (isset($linia[3]) && $linia[3] === ' ') break;
        }
        return $odp;
    };
    $pisz = function ($cmd, $oczekiwany) use ($gniazdo, $czytaj) {
        if ($cmd !== null) fwrite($gniazdo, $cmd . "\r\n");
        $odp = $czytaj();
        return strpos($odp, (string)$oczekiwany) === 0;
    };
    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    if (!$pisz(null, 220)) return false;
    if (!$pisz('EHLO ' . $host, 250)) return false;
    if (!$ssl) {
        if (!$pisz('STARTTLS', 220)) return false;
        if (!stream_socket_enable_crypto($gniazdo, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return false;
        if (!$pisz('EHLO ' . $host, 250)) return false;
    }
    if (SMTP_USER !== '') {
        if (!$pisz('AUTH LOGIN', 334)) return false;
        if (!$pisz(base64_encode(SMTP_USER), 334)) return false;
        if (!$pisz(base64_encode(SMTP_PASS), 235)) return false;
    }
    if (!$pisz('MAIL FROM:<' . NADAWCA . '>', 250)) return false;
    if (!$pisz('RCPT TO:<' . $do . '>', 250)) return false;
    if (!$pisz('DATA', 354)) return false;
    $wiadomosc = 'To: <' . $do . ">\r\n" . 'Subject: ' . naglowekUtf8($temat) . "\r\n" . implode("\r\n", $naglowki) . "\r\n\r\n" . $tresc;
    $wiadomosc = preg_replace('/^\./m', '..', $wiadomosc);
    if (!$pisz($wiadomosc . "\r\n.", 250)) return false;
    $pisz('QUIT', 221);
    fclose($gniazdo);
    return true;
}

function wyslijMail($do, $temat, $naglowki, $tresc) {
    if (SMTP_HOST !== '') return smtpWyslij($do, $temat, $naglowki, $tresc);
    return mail($do, naglowekUtf8($temat), $tresc, implode("\r\n", $naglowki), '-f' . NADAWCA);
}

// 1. Formularz do NA GOTOWO (z załącznikami)
list($naglowki, $tresc) = zbudujWiadomosc($imie, $email, $tekst, $html, $zalaczniki);
if (!wyslijMail(ODBIORCA, $temat, $naglowki, $tresc)) {
    odpowiedz(false, 'Nie udało się wysłać formularza. Spróbuj ponownie za chwilę albo napisz na ' . ODBIORCA . '.', 500);
}

// 2. Potwierdzenie dla klienta (kopia bez zdjęć). Błąd tutaj nie blokuje zgłoszenia.
$kopia = false;
if (POTWIERDZENIE) {
    $wstep = "Dzień dobry,\n\n";
    $htmlKlient = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#102416;line-height:1.5"><p>Dzień dobry,</p>';
    foreach ($kopiaAkapity as $akapit) {
        $czysty = str_replace('**', '', $akapit);
        $wstep .= $czysty . "\n\n";
        $e = htmlspecialchars($czysty, ENT_QUOTES, 'UTF-8');
        $htmlKlient .= '<p>' . (strpos($akapit, '**') === 0 ? '<strong>' . $e . '</strong>' : $e) . '</p>';
    }
    $htmlKlient .= '</div>' . $html
        . '<p style="font-family:Arial,sans-serif;font-size:14px;color:#102416;margin-top:16px">Zespół NA GOTOWO<br>' . htmlspecialchars(ODBIORCA, ENT_QUOTES, 'UTF-8') . '</p>';
    $stopka = "\nZespół NA GOTOWO\n" . ODBIORCA . "\n";
    list($nk, $tk) = zbudujWiadomosc('NA GOTOWO', ODBIORCA, $wstep . $tekst . $stopka, $htmlKlient, []);
    $kopia = (bool)wyslijMail($email, $kopiaTemat, $nk, $tk);
}

echo json_encode(['ok' => true, 'kopia' => $kopia], JSON_UNESCAPED_UNICODE);
