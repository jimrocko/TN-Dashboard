// Kamera und Fotos für den Teile-Scanner (Teileerkennung per Foto).

export const cameraAvailable = () => !!navigator.mediaDevices?.getUserMedia

/** Rückkamera im <video>-Element starten. Gibt eine Funktion zum Stoppen zurück. */
export async function startCamera(video) {
  const stream = await navigator.mediaDevices.getUserMedia({
    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 960 } },
    audio: false,
  })
  video.srcObject = stream
  video.setAttribute('playsinline', '')
  await video.play()
  return () => {
    stream.getTracks().forEach((t) => t.stop())
    video.srcObject = null
  }
}

// Fotos werden vor dem Hochladen verkleinert: schneller im Schul-WLAN, für die Erkennung reicht das.
const MAX = 1024

function toJpeg(source, w, h) {
  const scale = Math.min(1, MAX / Math.max(w, h))
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(w * scale)
  canvas.height = Math.round(h * scale)
  canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height)
  return new Promise((resolve, reject) =>
    canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Foto konnte nicht erstellt werden'))), 'image/jpeg', 0.88))
}

/** Aktuelles Kamerabild als JPEG. */
export const captureFrame = (video) => toJpeg(video, video.videoWidth, video.videoHeight)

/** Gewähltes Foto (Datei) verkleinert als JPEG. */
export async function shrinkFile(file) {
  const url = URL.createObjectURL(file)
  try {
    const img = new Image()
    img.src = url
    await img.decode()
    return await toJpeg(img, img.naturalWidth, img.naturalHeight)
  } finally {
    URL.revokeObjectURL(url)
  }
}
