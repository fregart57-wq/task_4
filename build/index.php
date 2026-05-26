<?php
require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");

if (!$USER->IsAdmin()) {
    echo "Доступ запрещён! Только для администратора.";
    die();
}

\Bitrix\Main\Loader::includeModule('iblock');

$IBLOCK_ID = 5; /* ID ИНФОБЛОКА ВАКАНСИЙ */ ;
$CSV_PATH  = $_SERVER['DOCUMENT_ROOT'] . '/local/parser/vacancy.csv';

if (empty($IBLOCK_ID)) {
    die("Ошибка: Не указан IBLOCK_ID инфоблока вакансий!");
}

if (!file_exists($CSV_PATH)) {
    die("Ошибка: CSV-файл не найден по пути:<br>" . $CSV_PATH);
}

$el = new CIBlockElement;

echo "Запуск парсера.<br><br>";

$arProps = [];

// Офисы (инфоблок 37)
$rs = CIBlockElement::GetList([], ['IBLOCK_ID' => 37], false, false, ['ID', 'NAME']);
while ($ob = $rs->GetNextElement()) {
    $arFields = $ob->GetFields();
    $key = mb_strtolower(trim(str_replace(['№', '�', '(', ')', '№'], '', $arFields['NAME'])));
    $arProps['OFFICE'][$key] = $arFields['ID'];
}

$rsProp = CIBlockPropertyEnum::GetList(["SORT" => "ASC"], ['IBLOCK_ID' => $IBLOCK_ID]);
while ($arProp = $rsProp->Fetch()) {
    $key = trim($arProp['VALUE']);
    $arProps[$arProp['PROPERTY_CODE']][$key] = $arProp['ID'];
}

$rsElements = CIBlockElement::GetList([], ['IBLOCK_ID' => $IBLOCK_ID], false, false, ['ID']);
while ($ar = $rsElements->Fetch()) {
    CIBlockElement::Delete($ar['ID']);
}

echo "Старые вакансии удалены.<br>";

$row = 0;
$success = 0;
$errors = 0;

if (($handle = fopen($CSV_PATH, "r")) !== false) {
    while (($data = fgetcsv($handle, 0, ",")) !== false) {
        $row++;
        if ($row === 1) continue;

        $PROP = [
            'ACTIVITY'     => trim($data[8] ?? ''),
            'FIELD'        => trim($data[11] ?? ''),
            'OFFICE'       => trim($data[1] ?? ''),
            'LOCATION'     => trim($data[2] ?? ''),
            'REQUIRE'      => trim($data[4] ?? ''),
            'DUTY'         => trim($data[5] ?? ''),
            'CONDITIONS'   => trim($data[6] ?? ''),
            'EMAIL'        => trim($data[12] ?? ''),
            'TYPE'         => trim($data[9] ?? ''),
            'SALARY_VALUE' => trim($data[7] ?? ''),
            'SCHEDULE'     => trim($data[10] ?? ''),
            'DATE'         => date('d.m.Y'),
        ];

        foreach ($PROP as $code => &$value) {
            $value = trim(str_replace(["\n", "\r", "\t"], ' ', $value));
            $value = preg_replace('/\s+/', ' ', $value);

            if (stripos($value, '?') !== false) {
                $parts = array_filter(array_map('trim', explode('?', $value)));
                array_shift($parts);
                $value = $parts;
                continue;
            }

            if (!empty($arProps[$code])) {
                $value = matchListValue($value, $arProps[$code], $code, $data);
            }
        }

        handleSalary($PROP, $arProps);

        $arLoad = [
            "MODIFIED_BY"     => $USER->GetID(),
            "IBLOCK_SECTION_ID" => false,
            "IBLOCK_ID"       => $IBLOCK_ID,
            "PROPERTY_VALUES" => $PROP,
            "NAME"            => trim($data[3] ?? 'Вакансия'),
            "ACTIVE"          => "Y",
        ];

        if ($PRODUCT_ID = $el->Add($arLoad)) {
            $success++;
            echo "+ {$row}: " . htmlspecialchars($arLoad['NAME']) . "<br>";
        } else {
            $errors++;
            echo "- {$row}: " . htmlspecialchars($el->LAST_ERROR) . "<br>";
        }
    }
    fclose($handle);
}

echo "<hr><b>ПАРСИНГ ЗАВЕРШЁН!</b><br>";
echo "Успешно добавлено: <b>{$success}</b><br>";
echo "Ошибок: <b>{$errors}</b><br>";

function matchListValue($value, $enumList, $code, $rawData = []) {
    if (empty($value) || empty($enumList)) {
        return false;
    }

    $valueLower = mb_strtolower(trim($value));

    foreach ($enumList as $propKey => $propId) {
        if (mb_strtolower($propKey) === $valueLower) {
            return $propId;
        }
    }

    foreach ($enumList as $propKey => $propId) {
        $propKeyLower = mb_strtolower($propKey);
        if (stripos($propKey, $value) !== false || stripos($value, $propKey) !== false) {
            return $propId;
        }
    }

    $arSimilar = [];
    foreach ($enumList as $propKey => $propId) {
        $propKeyLower = mb_strtolower($propKey);

        if ($code === 'OFFICE') {
            $search = $valueLower;
            if (stripos($valueLower, 'головн') !== false || stripos($valueLower, 'главн') !== false) {
                $search = 'головной офис';
            }
            similar_text($search, $propKeyLower, $percent);
        } else {
            similar_text($valueLower, $propKeyLower, $percent);
        }

        if ($percent >= 60) {
            $arSimilar[$percent] = $propId;
        }
    }

    if (!empty($arSimilar)) {
        ksort($arSimilar, SORT_NUMERIC);
        return array_pop($arSimilar);
    }

    return $value;
}

function handleSalary(&$PROP, $arProps) {
    $val = trim($PROP['SALARY_VALUE'] ?? '');
    if ($val === '' || $val === '-') {
        $PROP['SALARY_VALUE'] = '';
        return;
    }

    if (stripos($val, 'по договоренности') !== false || stripos($val, 'договор') !== false) {
        $PROP['SALARY_VALUE'] = '';
        $PROP['SALARY_TYPE'] = $arProps['SALARY_TYPE']['По договоренности'] ?? false;
        return;
    }

    $arSalary = explode(' ', $val);
    $first = mb_strtolower($arSalary[0] ?? '');

    if (in_array($first, ['от', 'до'])) {
        $key = ($first === 'от') ? 'От' : 'До';
        $PROP['SALARY_TYPE'] = $arProps['SALARY_TYPE'][$key] ?? false;
        array_shift($arSalary);
        $PROP['SALARY_VALUE'] = implode(' ', $arSalary);
    } else {
        $PROP['SALARY_TYPE'] = $arProps['SALARY_TYPE']['='] ?? false;
    }
}