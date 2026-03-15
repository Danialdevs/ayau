from pathlib import Path


PAGE_WIDTH = 612
PAGE_HEIGHT = 792
LEFT = 54
TOP = 750
LINE_HEIGHT = 15


def escape_pdf_text(text: str) -> str:
    return text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def add_line(lines: list[str], text: str, y: int, font: str = "F1", size: int = 11) -> None:
    escaped = escape_pdf_text(text)
    lines.append(f"BT /{font} {size} Tf 1 0 0 1 {LEFT} {y} Tm ({escaped}) Tj ET")


def build_content() -> str:
    lines: list[str] = []
    y = TOP

    add_line(lines, "App Summary", y, size=18)
    y -= 28

    add_line(lines, "What it is", y, font="F2", size=12)
    y -= LINE_HEIGHT
    add_line(
        lines,
        "The current workspace at C:\\Users\\d\\Videos\\prokty contains no repository files.",
        y,
    )
    y -= LINE_HEIGHT
    add_line(lines, "A functional description of the app was not found in repo.", y)
    y -= 24

    add_line(lines, "Who it is for", y, font="F2", size=12)
    y -= LINE_HEIGHT
    add_line(lines, "Primary user/persona: Not found in repo.", y)
    y -= 24

    add_line(lines, "What it does", y, font="F2", size=12)
    y -= LINE_HEIGHT
    bullets = [
        "Feature set: Not found in repo.",
        "User workflows: Not found in repo.",
        "UI surfaces or screens: Not found in repo.",
        "Integrations or external services: Not found in repo.",
        "Data storage or persistence behavior: Not found in repo.",
        "Background jobs, sync, or automation: Not found in repo.",
    ]
    for bullet in bullets:
        add_line(lines, f"- {bullet}", y)
        y -= LINE_HEIGHT
    y -= 9

    add_line(lines, "How it works", y, font="F2", size=12)
    y -= LINE_HEIGHT
    architecture = [
        "Evidence in workspace: root directory exists but is empty (0 items).",
        "Source code, package manifests, README files, and git metadata were not found.",
        "Components/services/data flow: Not found in repo.",
    ]
    for item in architecture:
        add_line(lines, item, y)
        y -= LINE_HEIGHT
    y -= 9

    add_line(lines, "How to run", y, font="F2", size=12)
    y -= LINE_HEIGHT
    steps = [
        "1. Open the workspace at C:\\Users\\d\\Videos\\prokty.",
        "2. Add or restore the actual application repository contents.",
        "3. Build/run commands: Not found in repo.",
    ]
    for step in steps:
        add_line(lines, step, y)
        y -= LINE_HEIGHT

    return "\n".join(lines)


def make_pdf_bytes() -> bytes:
    content = build_content().encode("latin-1", "replace")
    objects: list[bytes] = []

    def add_object(body: bytes) -> int:
        objects.append(body)
        return len(objects)

    add_object(b"<< /Type /Catalog /Pages 2 0 R >>")
    add_object(b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>")
    add_object(
        f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {PAGE_WIDTH} {PAGE_HEIGHT}] "
        "/Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>".encode(
            "ascii"
        )
    )
    add_object(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")
    add_object(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>")
    add_object(
        b"<< /Length "
        + str(len(content)).encode("ascii")
        + b" >>\nstream\n"
        + content
        + b"\nendstream"
    )

    pdf = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets = [0]
    for index, body in enumerate(objects, start=1):
        offsets.append(len(pdf))
        pdf.extend(f"{index} 0 obj\n".encode("ascii"))
        pdf.extend(body)
        pdf.extend(b"\nendobj\n")

    xref_offset = len(pdf)
    pdf.extend(f"xref\n0 {len(objects) + 1}\n".encode("ascii"))
    pdf.extend(b"0000000000 65535 f \n")
    for offset in offsets[1:]:
        pdf.extend(f"{offset:010d} 00000 n \n".encode("ascii"))
    pdf.extend(
        (
            f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\n"
            f"startxref\n{xref_offset}\n%%EOF\n"
        ).encode("ascii")
    )
    return bytes(pdf)


def main() -> None:
    output = Path("app_summary_one_page.pdf")
    output.write_bytes(make_pdf_bytes())
    print(output.resolve())


if __name__ == "__main__":
    main()
