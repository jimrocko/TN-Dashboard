#!/usr/bin/env python3
"""App-Icon erzeugen: Logo-Stein, unten rechts winkt Klemmi aus der Ecke.

Schreibt tools/app-icon.svg (eckig, für iOS „Zum Home-Bildschirm“, iOS rundet selbst ab) und
tools/app-icon-rounded.svg (abgerundet, PWA-Icons). PNGs danach mit `node tools/render-icons.cjs`
(braucht Playwright, nur in der Entwicklungsumgebung). Klemmi kommt aus frontend/src/tutorial/Klemmi.vue.
"""
import re
from pathlib import Path

HERE = Path(__file__).parent
k = (HERE / '../frontend/src/tutorial/Klemmi.vue').read_text()
body = k[k.index('<g class="body">'):]
body = body[:body.index('\n    </g>\n') + len('\n    </g>')]
body = re.sub(r'<!--.*?-->', '', body)
body = body.replace('<ellipse class="mouth" cx="50" cy="31" rx="6" ry="4" fill="#3a1010" />', '')  # lächelt
body = body.replace('<g class="arm arm-r">', '<g class="arm arm-r" transform="rotate(-140 72 52)">')  # winkt


def icon(rounded: bool) -> str:
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <!-- App-Icon: Logo-Stein, unten rechts winkt Klemmi aus der Ecke. Erzeugt von tools/make-icons.py -->
  <defs><clipPath id="c"><rect width="512" height="512" rx="{112 if rounded else 0}"/></clipPath></defs>
  <g clip-path="url(#c)">
  <rect width="512" height="512" fill="#0a0f17"/>
  <g transform="translate(-52 -44)">
    <g fill="none" stroke="#e8edf3" stroke-width="22" stroke-linejoin="round">
      <path d="M256 120 L400 190 L256 260 L112 190 Z" fill="#e8edf3" fill-opacity=".95"/>
      <path d="M112 190 V330 L256 400 V260 Z" fill="#c5ced9"/>
      <path d="M400 190 V330 L256 400 V260 Z" fill="#9aa6b6"/>
    </g>
    <g fill="#0a0f17" fill-opacity=".25">
      <ellipse cx="216" cy="172" rx="26" ry="13"/><ellipse cx="296" cy="172" rx="26" ry="13"/>
      <ellipse cx="216" cy="210" rx="26" ry="13"/><ellipse cx="296" cy="210" rx="26" ry="13"/>
    </g>
  </g>
  <g transform="translate(560 262) rotate(-8) scale(-2.55 2.55)">
    {body}
  </g>
  </g>
</svg>
'''


(HERE / 'app-icon.svg').write_text(icon(False))
(HERE / 'app-icon-rounded.svg').write_text(icon(True))
print('tools/app-icon.svg und tools/app-icon-rounded.svg geschrieben')
