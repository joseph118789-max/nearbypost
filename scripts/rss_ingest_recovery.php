<?php
$feeds = [
  ["url" => "https://www.freemalaysiatoday.com/feed/", "label" => "FMT Nation", "name" => "Free Malaysia Today", "domain" => "www.freemalaysiatoday.com"],
  ["url" => "https://www.bernama.com/en/rss.php?id=news", "label" => "Bernama", "name" => "Bernama", "domain" => "www.bernama.com"],
  ["url" => "https://www.malaymail.com/feed/rss/malaysia", "label" => "Malay Mail", "name" => "Malay Mail", "domain" => "www.malaymail.com"],
];
function cleanText($html) {
  $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace('/\s+/u', ' ', $text ?? '');
  return trim($text ?? '');
}
function blockedPath($url) {
  $lower = strtolower((string)$url);
  foreach (['/tag/','/tags/','/category/','/categories/','/archive','/search','/video/','/videos/','/gallery/','/galleries/','/photo/','/photos/','/topic/','/topics/','/live/'] as $part) {
    if (str_contains($lower, $part)) return true;
  }
  return false;
}
$items = [];
$seen = [];
foreach ($feeds as $feed) {
  $xml = @simplexml_load_file($feed['url'], 'SimpleXMLElement', LIBXML_NOCDATA);
  if (!$xml || empty($xml->channel->item)) continue;
  foreach ($xml->channel->item as $it) {
    $url = trim((string)($it->link ?? $it->guid ?? ''));
    if (!$url || blockedPath($url)) continue;
    $key = preg_replace('/[#?].*$/', '', $url);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $items[] = [
      'title' => mb_substr(cleanText((string)($it->title ?? 'Untitled')), 0, 500),
      'url' => $url,
      'source' => $feed['label'],
      'source_label' => $feed['label'],
      'source_name' => $feed['name'],
      'source_domain' => $feed['domain'],
      'published_at' => (string)($it->pubDate ?? gmdate('c')),
      'summary' => mb_substr(cleanText((string)($it->description ?? '')), 0, 800),
      'primary_category' => 'nation',
      'secondary_category' => 'general',
    ];
    if (count($items) >= 30) break 2;
  }
}
if (!$items) {
  fwrite(STDOUT, "No items fetched\n");
  exit(0);
}
$payload = json_encode(['items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$ch = curl_init('http://127.0.0.1:8000/api/internal/ingest/batch');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_TIMEOUT => 90,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
fwrite(STDOUT, "HTTP $code\n$res\n");
if ($err) {
  fwrite(STDERR, "CURLERR: $err\n");
  exit(1);
}
if ($code >= 400) exit(1);
