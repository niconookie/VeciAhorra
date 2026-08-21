"""R1C legal-source conversion checks and browser smoke helpers.

The generated repository HTML is the reviewable publication source. DOCX files stay
outside Git and are used only to prove provenance and completeness.
"""
from __future__ import annotations

import argparse
import hashlib
import html
import json
import re
import time
import urllib.parse
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

NS = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
W = "{" + NS["w"] + "}"

TERMS = Path(r"C:\Users\UserMSI\Documents\VeciAhorra-Legal\V_ES_P_01 Términos y Condiciones de Uso VeciAhorra.docx")
PRIVACY = Path(r"C:\Users\UserMSI\Documents\VeciAhorra-Legal\V_E_P_02 Politica de privacidad y tratamiento datos personales (WEB).docx")
ROOT = Path(__file__).resolve().parents[2]


def paragraphs(path: Path) -> list[tuple[str, str, bool]]:
    with zipfile.ZipFile(path) as archive:
        root = ET.fromstring(archive.read("word/document.xml"))
    result: list[tuple[str, str, bool]] = []
    body = root.find("w:body", NS)
    if body is None:
        raise RuntimeError("DOCX sin cuerpo")
    for node in body:
        if node.tag != W + "p":
            continue
        parts: list[str] = []
        for item in node.iter():
            if item.tag == W + "t":
                parts.append(item.text or "")
            elif item.tag in {W + "br", W + "tab"}:
                parts.append(" ")
        text = "".join(parts).strip()
        if not text:
            continue
        style_node = node.find("w:pPr/w:pStyle", NS)
        style = style_node.get(W + "val", "") if style_node is not None else ""
        numbered = node.find("w:pPr/w:numPr", NS) is not None
        result.append((text, style, numbered))
    return result


def clean_text(value: str, privacy: bool = False) -> str:
    replacements = {
        "comunidades y o barrios": "comunidades y/o barrios",
        "Principio del formulario": "",
        "Final del formulario": "",
        "Goodbe Analitics SpA_": "Goodbe Analitics SpA",
    }
    if privacy:
        replacements.update({"POLITICA": "POLÍTICA", "Correo electrónica": "Correo electrónico"})
    for old, new in replacements.items():
        value = value.replace(old, new)
    value = re.sub(r"\s+", " ", value).strip()
    value = value.replace("CAPITULO: Definiciones", "CAPÍTULO II: Definiciones")
    value = re.sub(r"^CAPITULO\b", "CAPÍTULO", value)
    value = re.sub(r"^(CAPÍTULO\s+[IVXLCDM]+)(?!:)\s+", r"\1: ", value)
    return value


def body_start(items: list[tuple[str, str, bool]], needle: str) -> int:
    matches = [i for i, (text, _, _) in enumerate(items) if needle.lower() in text.lower()]
    if not matches:
        raise RuntimeError(f"No se pudo separar el índice del cuerpo: {needle}")
    return matches[-1] if len(matches) > 1 else matches[0]


def heading_level(text: str, style: str) -> int | None:
    normalized = style.lower()
    match = re.search(r"(?:heading|ttulo|titulo)(\d+)", normalized)
    if match:
        return min(4, max(2, int(match.group(1)) + 1))
    if re.match(r"^CAP[IÍ]TULO\b", text, re.I):
        return 2
    if len(text) <= 100 and not text.endswith(".") and style and "normal" not in normalized:
        return 3
    return None


def render_body(items: list[tuple[str, str, bool]], *, privacy: bool) -> str:
    output: list[str] = []
    in_list = False
    skip_observation = False
    for raw, style, numbered in items:
        text = clean_text(raw, privacy)
        if not text:
            continue
        level = heading_level(text, style)
        if text.lower().rstrip(".") == "observación jurídica":
            skip_observation = True
            continue
        if skip_observation:
            if level is None:
                continue
            skip_observation = False
        if numbered:
            if not in_list:
                output.append("<ul>")
                in_list = True
            output.append(f"<li>{html.escape(text)}</li>")
            continue
        if in_list:
            output.append("</ul>")
            in_list = False
        if level is not None:
            output.append(f"<h{level}>{html.escape(text)}</h{level}>")
        else:
            output.append(f"<p>{html.escape(text)}</p>")
    if in_list:
        output.append("</ul>")
    return "\n".join(output)


def terms_html() -> str:
    items = paragraphs(TERMS)
    start = body_start(items, "CAPITULO I: Objeto")
    body = render_body(items[start:], privacy=False)
    return """<article class="va-legal-document" data-document-code="V-ES-P-01" data-document-version="01">
<header>
<h1>Términos y Condiciones de Uso de VeciAhorra</h1>
<dl><dt>Código</dt><dd>V-ES-P-01</dd><dt>Versión</dt><dd>01</dd><dt>Vigencia</dt><dd>22/07/2026</dd></dl>
<p>Estos Términos y Condiciones son emitidos por Goodbe Analitics SpA, RUT 78.457.575-6, con domicilio en Morande 835 Piso 11, Oficina 1107, Santiago. Contacto: <a href="mailto:contacto@veciahorra.cl">contacto@veciahorra.cl</a>. Sitio: <a href="https://veciahorra.cl/">https://veciahorra.cl/</a>.</p>
</header>
""" + body + "\n</article>\n"


def privacy_html() -> str:
    items = paragraphs(PRIVACY)
    start = body_start(items, "Introducción")
    body = render_body(items[start:], privacy=True)
    addition = """
<section aria-labelledby="va-privacy-onboarding">
<h2 id="va-privacy-onboarding">Solicitudes de incorporación de minimarkets</h2>
<p>Esta Política también se aplica a postulantes, solicitantes de incorporación de minimarkets y responsables que inician un onboarding comercial.</p>
<p>La solicitud inicial recopila el correo electrónico y el RUT del responsable, la aceptación de los documentos legales y identificadores técnicos protegidos utilizados para seguridad e idempotencia.</p>
<h3>Finalidades</h3>
<ul><li>Recibir y tramitar la solicitud.</li><li>Prevenir duplicados, fraude y abuso.</li><li>Comunicarse sobre el onboarding.</li><li>Conservar evidencia de aceptación legal.</li><li>Gestionar futuras etapas de incorporación.</li></ul>
<h3>Conservación</h3>
<p>Las solicitudes activas se conservarán durante su tramitación. Las solicitudes materializadas se conservarán según la relación comercial y las obligaciones legales aplicables. Las solicitudes abandonadas o rechazadas se conservarán durante el tiempo necesario para seguridad, prevención de fraude, defensa de derechos y cumplimiento de obligaciones legales. Los datos se eliminarán o anonimizarán cuando cesen las finalidades y fundamentos aplicables.</p>
</section>
"""
    return """<article class="va-legal-document" data-document-code="V-ES-P-02" data-document-version="01">
<header>
<h1>Política de Privacidad y Tratamiento de Datos Personales</h1>
<dl><dt>Código</dt><dd>V-ES-P-02</dd><dt>Versión</dt><dd>01</dd><dt>Vigencia</dt><dd>30/07/2026</dd></dl>
</header>
""" + addition + body + "\n</article>\n"


def generate() -> None:
    target = ROOT / "resources" / "legal"
    target.mkdir(parents=True, exist_ok=True)
    (target / "V-ES-P-01-v01.html").write_text(terms_html(), encoding="utf-8", newline="\n")
    (target / "V-ES-P-02-v01.html").write_text(privacy_html(), encoding="utf-8", newline="\n")


def certify() -> None:
    expected = {
        TERMS: ("12320984313d2d9bcaadcd46915ac74d5bbf0854faf86a25a1cbbc345ddf275c", 76),
        PRIVACY: ("1ec1fc3410eb1e90ee34e2c7e629d6ea01ea17db66c463cd70eda77d3673c25d", 13),
    }
    for path, (digest, pages) in expected.items():
        assert path.exists() and hashlib.sha256(path.read_bytes()).hexdigest() == digest
        with zipfile.ZipFile(path) as archive:
            app = ET.fromstring(archive.read("docProps/app.xml"))
        actual_pages = next(int(node.text or 0) for node in app if node.tag.endswith("Pages"))
        assert actual_pages == pages
    terms = (ROOT / "resources/legal/V-ES-P-01-v01.html").read_text(encoding="utf-8")
    privacy = (ROOT / "resources/legal/V-ES-P-02-v01.html").read_text(encoding="utf-8")
    assert len(terms) > 70_000 and len(privacy) > 12_000
    assert "Observación jurídica" not in terms and "Principio del formulario" not in terms
    assert "Goodbe Analitics SpA" in terms and "78.457.575-6" in terms
    assert "solicitantes de incorporación de minimarkets" in privacy
    assert "<script" not in terms.lower() and "<script" not in privacy.lower()
    for broken in ("SpARUT", "6Domicilio", "SantiagoCorreo", "SpACorreo", ".clSitio"):
        assert broken not in terms and broken not in privacy
    terms_items = paragraphs(TERMS)
    skip = False
    certified_terms = 0
    for raw, style, _ in terms_items[body_start(terms_items, "CAPITULO I: Objeto"):]:
        cleaned = clean_text(raw)
        if cleaned.lower().rstrip(".") == "observación jurídica":
            skip = True
            continue
        if skip:
            if heading_level(cleaned, style) is None:
                continue
            skip = False
        if cleaned:
            assert html.escape(cleaned) in terms
            certified_terms += 1
    privacy_items = paragraphs(PRIVACY)
    certified_privacy = 0
    for raw, _, _ in privacy_items[body_start(privacy_items, "Introducción"):]:
        cleaned = clean_text(raw, True)
        if cleaned:
            assert html.escape(cleaned) in privacy
            certified_privacy += 1
    # Independent separator audit: inspect Word XML recursively instead of using
    # the generation extractor, then require each normalized paragraph in HTML.
    for path, rendered, privacy_mode in ((TERMS, terms, False), (PRIVACY, privacy, True)):
        with zipfile.ZipFile(path) as archive:
            document = ET.fromstring(archive.read("word/document.xml"))
        body = document.find("w:body", NS)
        assert body is not None
        references: list[tuple[str, str]] = []
        for paragraph in body.findall("w:p", NS):
            tokens: list[str] = []
            for child in paragraph.iter():
                local = child.tag.rsplit("}", 1)[-1]
                if local == "t":
                    tokens.append(child.text or "")
                if local in {"br", "tab"}:
                    tokens.append(" ")
            candidate = clean_text("".join(tokens), privacy_mode)
            if candidate:
                style_node = paragraph.find("w:pPr/w:pStyle", NS)
                style = style_node.get(W + "val", "") if style_node is not None else ""
                references.append((candidate, style))
        start_needle = "Introducción" if privacy_mode else "CAPÍTULO I: Objeto"
        starts = [i for i, (value, _) in enumerate(references) if start_needle.lower() in value.lower()]
        assert starts
        skip_observation = False
        for reference, style in references[starts[-1]:]:
            if reference.lower().rstrip(".") == "observación jurídica":
                skip_observation = True
                continue
            if skip_observation:
                if heading_level(reference, style) is None:
                    continue
                skip_observation = False
            assert html.escape(reference) in rendered
    print("R1C_LEGAL_SOURCE=PASS", len(terms), len(privacy), f"paragraphs={certified_terms}/{certified_privacy}")


def http_smoke() -> None:
    import requests
    requests.packages.urllib3.disable_warnings()
    url = "https://localhost/Minimarket/registro-minimarket/"
    session = requests.Session()
    first = session.get(url, verify=False, timeout=30)
    second = requests.get(url, verify=False, timeout=30)
    key_pattern = r'name="idempotency_key" value="([a-f0-9]{64})"'
    nonce_pattern = r'name="_va_minimarket_onboarding_nonce" value="([^"]+)"'
    key = re.search(key_pattern, first.text)
    other_key = re.search(key_pattern, second.text)
    nonce = re.search(nonce_pattern, first.text)
    assert first.status_code == 200 and key and other_key and nonce and key.group(1) != other_key.group(1)
    data = {
        "veciahorra_minimarket_onboarding": "1",
        "_va_minimarket_onboarding_nonce": nonce.group(1),
        "account_email": "r1c.http.synthetic@example.com",
        "owner_rut": "12.345.678-5",
        "terms_accepted": "1",
        "idempotency_key": key.group(1),
    }
    headers = {"Origin": "https://localhost", "Referer": url}
    accepted = session.post(url, data=data, headers=headers, verify=False, timeout=30)
    replay = session.post(url, data=data, headers=headers, verify=False, timeout=30)
    accepted_ids = re.findall(r"onb_[a-f0-9]{40}", accepted.text)
    replay_ids = re.findall(r"onb_[a-f0-9]{40}", replay.text)
    assert accepted.status_code == replay.status_code == 200
    assert accepted_ids and replay_ids and accepted_ids[0] == replay_ids[0]
    assert accepted.headers.get("Cache-Control") == "private, no-store, max-age=0"
    assert accepted.headers.get("Referrer-Policy") == "same-origin"
    print("R1C_HTTP=PASS unique_get_keys=PASS replay=PASS no_store=PASS public_id=" + accepted_ids[0])


def selenium_smoke() -> None:
    from selenium import webdriver
    from selenium.webdriver.common.by import By
    from selenium.webdriver.common.keys import Keys
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as ec

    url = "https://localhost/Minimarket/registro-minimarket/"

    def driver(*, javascript: bool, mobile: bool = False):
        options = webdriver.ChromeOptions()
        options.add_argument("--headless=new")
        options.add_argument("--ignore-certificate-errors")
        options.add_argument("--disable-gpu")
        options.add_argument("--no-first-run")
        options.set_capability("goog:loggingPrefs", {"performance": "ALL"})
        if mobile:
            options.add_argument("--window-size=390,844")
        else:
            options.add_argument("--window-size=1366,900")
        if not javascript:
            options.add_experimental_option("prefs", {"profile.managed_default_content_settings.javascript": 2})
        return webdriver.Chrome(options=options)

    enabled = driver(javascript=True)
    try:
        enabled.get(url)
        assert enabled.find_element(By.ID, "va-account_email").is_displayed()
        assert len(enabled.find_elements(By.CSS_SELECTOR, ".va-onboarding__check a")) == 2
        enabled.find_element(By.ID, "va-account_email").send_keys("r1c.browser.synthetic@example.com")
        enabled.find_element(By.ID, "va-owner_rut").send_keys("12.345.678-5")
        enabled.find_element(By.ID, "va-terms_accepted").send_keys(Keys.SPACE)
        form = enabled.find_element(By.CSS_SELECTOR, "[data-va-onboarding-form]")
        first_submit = enabled.execute_script("return arguments[0].dispatchEvent(new Event('submit',{cancelable:true}))", form)
        second_submit = enabled.execute_script("return arguments[0].dispatchEvent(new Event('submit',{cancelable:true}))", form)
        assert first_submit is True and second_submit is False
        enabled.execute_script("arguments[0].dataset.submitting=''; HTMLFormElement.prototype.submit.call(arguments[0])", form)
        time.sleep(2)
        if not enabled.find_elements(By.CSS_SELECTOR, "[data-va-onboarding-result]"):
            alerts = [node.text for node in enabled.find_elements(By.CSS_SELECTOR, "[data-va-onboarding-alert]")]
            requests_seen = []
            for entry in enabled.get_log("performance"):
                message = json.loads(entry["message"])["message"]
                if message["method"] == "Network.requestWillBeSent":
                    request = message["params"]["request"]
                    if request["method"] == "POST" and "registro-minimarket" in request["url"]:
                        technical = {k.lower(): v for k, v in request["headers"].items() if k.lower() in {"origin", "referer", "content-type", "content-length"}}
                        technical["field_names"] = sorted(urllib.parse.parse_qs(request.get("postData", ""), keep_blank_values=True).keys())
                        requests_seen.append(technical)
            raise AssertionError(f"POST navegador sin resultado: url={enabled.current_url} alerts={alerts} headers={requests_seen}")
        result = WebDriverWait(enabled, 20).until(ec.visibility_of_element_located((By.CSS_SELECTOR, "[data-va-onboarding-result]")))
        assert re.fullmatch(r"onb_[a-f0-9]{40}", enabled.find_element(By.CSS_SELECTOR, "[data-va-onboarding-code]").text)
        assert result.get_attribute("role") == "status"
    finally:
        enabled.quit()

    disabled = driver(javascript=False)
    try:
        disabled.get(url)
        disabled.find_element(By.ID, "va-account_email").send_keys("r1c.nojs.synthetic@example.com")
        disabled.find_element(By.ID, "va-owner_rut").send_keys("12.345.678-5")
        disabled.find_element(By.ID, "va-terms_accepted").send_keys(Keys.SPACE)
        disabled.execute_script("HTMLFormElement.prototype.submit.call(arguments[0])", disabled.find_element(By.CSS_SELECTOR, "[data-va-onboarding-form]"))
        WebDriverWait(disabled, 20).until(ec.visibility_of_element_located((By.CSS_SELECTOR, "[data-va-onboarding-result]")))
    finally:
        disabled.quit()

    mobile = driver(javascript=True, mobile=True)
    try:
        mobile.get(url)
        card = mobile.find_element(By.CSS_SELECTOR, ".va-onboarding__card")
        viewport = mobile.execute_script("return document.documentElement.clientWidth")
        assert card.rect["width"] <= viewport and card.is_displayed()
    finally:
        mobile.quit()
    print("R1C_BROWSER=PASS desktop=PASS mobile=PASS javascript=PASS no_javascript=PASS keyboard_semantics=PASS")


def security_smoke() -> None:
    import requests
    requests.packages.urllib3.disable_warnings()
    url = "https://localhost/Minimarket/registro-minimarket/"
    session = requests.Session()
    page = session.get(url, verify=False, timeout=30)
    key = re.search(r'name="idempotency_key" value="([a-f0-9]{64})"', page.text)
    nonce = re.search(r'name="_va_minimarket_onboarding_nonce" value="([^"]+)"', page.text)
    assert key and nonce
    data = {"veciahorra_minimarket_onboarding":"1","_va_minimarket_onboarding_nonce":nonce.group(1),"account_email":"bad@example","owner_rut":"12.345.678-5","terms_accepted":"1","idempotency_key":key.group(1)}
    rejected = session.post(url, data=data, headers={"Origin":"https://evil.example"}, verify=False, timeout=30)
    assert rejected.status_code == 403
    statuses=[]
    for _ in range(6):
        response=session.post(url,data=data,headers={"Origin":"https://localhost","Referer":url},verify=False,timeout=30)
        statuses.append(response.status_code)
        if response.status_code == 422:
            assert 'id="va-owner_rut" name="owner_rut" type="text" required inputmode="text" value=""' in response.text
    assert statuses == [422,422,422,422,422,429]
    assert response.headers.get("Retry-After") and int(response.headers["Retry-After"]) > 0
    print("R1C_SECURITY_HTTP=PASS csrf=403 validation=422 rate_limit=429 retry_after=PASS rut_repopulation=BLOCKED")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--generate-legal", action="store_true")
    parser.add_argument("--http-smoke", action="store_true")
    parser.add_argument("--selenium-smoke", action="store_true")
    parser.add_argument("--security-smoke", action="store_true")
    args = parser.parse_args()
    if args.generate_legal:
        generate()
    certify()
    if args.http_smoke:
        http_smoke()
    if args.selenium_smoke:
        selenium_smoke()
    if args.security_smoke:
        security_smoke()
