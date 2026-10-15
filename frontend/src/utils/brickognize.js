// Direkter Aufruf von Brickognize aus dem Browser. Nur für die Demo-Version ohne Server;
// mit Server läuft die Erkennung über api/?r=recognize (api/lib/Brickognize.php).
export const BRICKOGNIZE_URL = 'https://api.brickognize.com/predict/'

/** Gleiche Felder wie Brickognize::normalize() in PHP. */
export function normalize(data) {
  return (data?.items ?? []).filter((it) => it.id !== undefined).slice(0, 6).map((it) => ({
    id: String(it.id),
    name: String(it.name ?? ''),
    type: String(it.type ?? 'part'),
    category: String(it.category ?? ''),
    score: Math.round(Number(it.score ?? 0) * 1000) / 1000,
    img_url: it.img_url ?? null,
    bricklink_url: (it.external_sites ?? []).find((s) => /bricklink/i.test(s.name ?? ''))?.url ?? null,
  }))
}

export async function predict(blob) {
  const fd = new FormData()
  fd.append('query_image', blob, 'foto.jpg')
  let res
  try {
    res = await fetch(BRICKOGNIZE_URL, { method: 'POST', body: fd, headers: { Accept: 'application/json' } })
  } catch {
    throw new Error('Erkennungsdienst nicht erreichbar (Internet nötig).')
  }
  if (!res.ok) throw new Error(`Erkennungsdienst antwortet mit Fehler (HTTP ${res.status})`)
  return normalize(await res.json())
}
