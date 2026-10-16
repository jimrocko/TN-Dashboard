<?php

/** BrickStore-Dateien (.bsx, XML) lesen und schreiben, dazu ein kleines ZIP ohne Zusatzmodul. */
final class Bsx
{
    /** Positionen einer BSX-Datei (wie parse_bsx im Original). Status X und Menge 0 werden übersprungen. */
    public static function parse(string $content): array
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($xml === false) throw new RuntimeException('Datei ist keine gültige BrickStore-BSX/XML-Datei');
        if (!in_array($xml->getName(), ['BrickStoreXML', 'BrickStockXML'], true) || !isset($xml->Inventory)) {
            throw new RuntimeException('BrickStore-Inventar wurde in der Datei nicht gefunden');
        }
        $inv = $xml->Inventory;
        $currency = strtoupper((string) ($inv['Currency'] ?? '') ?: 'USD');
        $rows = [];
        $idx = 0;
        foreach ($inv->Item as $item) {
            $t = fn (string $tag, string $def = '') => isset($item->$tag) ? trim((string) $item->$tag) : $def;
            $no = $t('ItemID');
            $type = $t('ItemTypeID');
            $cond = strtoupper($t('Condition')) ?: 'N';
            $i = $idx++;
            if ($no === '' || $type === '' || !in_array($cond, ['N', 'U'], true)) continue;
            $qty = (int) (float) ($t('Qty', '0') ?: 0);
            $status = strtoupper($t('Status', 'I')) ?: 'I';
            if ($status === 'X' || $qty <= 0) continue;
            $rows[] = [
                'item_type' => $type, 'item_no' => $no, 'color_id' => is_numeric($t('ColorID', '-1')) ? (int) $t('ColorID', '-1') : -1,
                'condition' => $cond, 'quantity' => $qty, 'price' => (float) ($t('Price', '0') ?: 0),
                'lot_id' => (int) ($t('LotID', '0') ?: 0) ?: null, 'item_name' => $t('ItemName'), 'color_name' => $t('ColorName'),
                'remarks' => $t('Remarks'), 'category_name' => $t('CategoryName'), 'comments' => $t('Comments'), 'index' => $i,
            ];
        }
        if (!$rows) throw new RuntimeException('Keine verwendbaren Bestandspositionen in der BrickStore-Datei gefunden');
        return [$rows, $currency];
    }

    /** BSX-Datei schreiben: $items = Liste von [Tag => Wert]. */
    public static function build(array $items): string
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $root = $doc->appendChild($doc->createElement('BrickStoreXML'));
        $inv = $root->appendChild($doc->createElement('Inventory'));
        $inv->setAttribute('Currency', 'EUR');
        foreach ($items as $values) {
            $el = $inv->appendChild($doc->createElement('Item'));
            foreach ($values as $tag => $v) $el->appendChild($doc->createElement($tag))->appendChild($doc->createTextNode((string) $v));
        }
        return $doc->saveXML();
    }

    /** BSX-Positionen in die Form der BrickLink-Bestandsliste bringen (Bemerkung = Lagerort). */
    public static function toInventory(array $rows): array
    {
        $types = ['P' => 'PART', 'M' => 'MINIFIG', 'S' => 'SET', 'B' => 'BOOK', 'G' => 'GEAR', 'C' => 'CATALOG', 'I' => 'INSTRUCTION', 'O' => 'ORIGINAL_BOX'];
        $out = [];
        foreach ($rows as $n => $r) {
            $out[] = [
                // Ohne LotID eine feste Ersatz-Nr. (Zeile), damit erneutes Einlesen dieselben Lose trifft
                'inventory_id' => $r['lot_id'] ?? (900000000 + $n),
                'item' => ['no' => $r['item_no'], 'name' => $r['item_name'], 'type' => $types[strtoupper($r['item_type'])] ?? strtoupper($r['item_type']),
                    'category_name' => $r['category_name']],
                'color_id' => max(0, $r['color_id']), 'color_name' => $r['color_name'],
                'quantity' => $r['quantity'], 'unit_price' => $r['price'], 'new_or_used' => $r['condition'],
                'remarks' => $r['remarks'], 'description' => $r['comments'],
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- ZIP

    /** ZIP mit mehreren Dateien (ZipArchive, falls vorhanden; sonst einfacher Eigenbau, unkomprimiert). */
    public static function zip(array $files): string
    {
        $data = '';
        $dir = '';
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $len = strlen($content);
            $head = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, 0x21, $crc, $len, $len, strlen($name), 0);
            $dir .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, 0, 0x21, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, strlen($data)) . $name;
            $data .= $head . $name . $content;
        }
        return $data . $dir . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($dir), strlen($data), 0);
    }

    /** Eine Datei aus einem ZIP lesen (unkomprimiert oder Deflate). */
    public static function unzip(string $zip, string $want): ?string
    {
        $end = strrpos($zip, "PK\x05\x06");
        if ($end === false) throw new RuntimeException('Transferdatei ist kein gültiges ZIP.');
        $e = unpack('Vsig/vdisk/vstart/ventries/vtotal/Vsize/Voffset', substr($zip, $end, 22));
        $p = $e['offset'];
        for ($i = 0; $i < $e['total']; $i++) {
            $c = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voffset', substr($zip, $p, 46));
            $name = substr($zip, $p + 46, $c['nlen']);
            $p += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
            if ($name !== $want) continue;
            $l = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen', substr($zip, $c['offset'], 30));
            $raw = substr($zip, $c['offset'] + 30 + $l['nlen'] + $l['xlen'], $c['csize']);
            return match ($c['method']) { 0 => $raw, 8 => gzinflate($raw) ?: null, default => null };
        }
        return null;
    }
}
