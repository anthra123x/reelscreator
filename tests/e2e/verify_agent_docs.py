#!/usr/bin/env python3
"""
Automated verification script for ReelForge agent documentation and production exclusion rules.
Validates:
1. Root README.md module map and all relative links.
2. All 21 local READMEs exist and strictly contain the 8 mandatory sections in exact order.
3. Absence of emojis across all documentation files.
4. Absence of forbidden files (AGENTS.md, GEMINI.md).
5. Presence of production exclusion rules (.dockerignore).
6. Zero markdown files in production bundle (frontend/dist).
"""

import os
import sys
import re

ROOT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "../.."))

LOCAL_READMES = [
    "rust-engine/README.md",
    "rust-engine/crates/reelforge-core/README.md",
    "rust-engine/crates/reelforge-downloader/README.md",
    "rust-engine/crates/reelforge-cutter/README.md",
    "rust-engine/crates/reelforge-server/README.md",
    "rust-engine/crates/reelforge-cli/README.md",
    "backend/README.md",
    "backend/app/Modules/Videos/README.md",
    "backend/app/Modules/Clips/README.md",
    "backend/app/Modules/SocialAccounts/README.md",
    "backend/app/Modules/Publishing/README.md",
    "frontend/README.md",
    "frontend/src/app/core/README.md",
    "frontend/src/app/layout/README.md",
    "frontend/src/app/shared/README.md",
    "frontend/src/app/features/dashboard/README.md",
    "frontend/src/app/features/video-upload/README.md",
    "frontend/src/app/features/clips/README.md",
    "frontend/src/app/features/social-accounts/README.md",
    "frontend/src/app/features/publish/README.md",
    "tests/e2e/README.md",
]

MANDATORY_SECTIONS = [
    "## 1. Propósito y alcance",
    "## 2. Contratos y API",
    "## 3. Dependencias",
    "## 4. Mapa de archivos",
    "## 5. Lógica y flujo",
    "## 6. Decisiones de diseño y gotchas",
    "## 7. Estado y últimos cambios",
    "## 8. Cómo verificar",
]

EMOJI_PATTERN = re.compile(
    r"[\U00010000-\U0010ffff]|[\u200d\u2600-\u27bf\u2300-\u23ff\u2b50-\u2b55\u3030\u00a9\u00ae\u2122]"
)

def verify_root_readme():
    print("[1/6] Verificando README.md raiz y mapa de modulos...")
    root_path = os.path.join(ROOT_DIR, "README.md")
    if not os.path.exists(root_path):
        print("  FALLO: README.md raiz no existe.")
        return False

    with open(root_path, "r", encoding="utf-8") as f:
        content = f.read()

    if "## Mapa de módulos" not in content and "## Mapa de modulos" not in content:
        print("  FALLO: README.md no contiene seccion '## Mapa de módulos'.")
        return False

    links = re.findall(r"\[([^\]]+)\]\(([^)]+)\)", content)
    if len(links) < len(LOCAL_READMES):
        print(f"  FALLO: Se esperaban al menos {len(LOCAL_READMES)} enlaces, encontrados {len(links)}.")
        return False

    missing_links = []
    for text, link in links:
        target = os.path.join(ROOT_DIR, link)
        if not os.path.exists(target):
            missing_links.append(link)

    if missing_links:
        print(f"  FALLO: Enlaces rotos en README.md: {missing_links}")
        return False

    print(f"  OK: README.md raiz indexa {len(links)} modulos con enlaces relativos validos.")
    return True

def verify_local_readmes():
    print(f"[2/6] Verificando {len(LOCAL_READMES)} READMEs locales y sus 8 secciones...")
    errors = []

    for rel_path in LOCAL_READMES:
        full_path = os.path.join(ROOT_DIR, rel_path)
        if not os.path.exists(full_path):
            errors.append(f"{rel_path}: Archivo no encontrado.")
            continue

        with open(full_path, "r", encoding="utf-8") as f:
            lines = f.readlines()

        h2_headings = [line.strip() for line in lines if line.startswith("## ")]

        if h2_headings != MANDATORY_SECTIONS:
            errors.append(
                f"{rel_path}: Discrepancia de secciones.\n"
                f"    Obtenido: {h2_headings}\n"
                f"    Esperado: {MANDATORY_SECTIONS}"
            )

    if errors:
        for err in errors:
            print(f"  FALLO: {err}")
        return False

    print(f"  OK: Los {len(LOCAL_READMES)} READMEs locales cumplen estrictamente con las 8 secciones obligatorias.")
    return True

def verify_no_emojis():
    print("[3/6] Verificando ausencia total de emojis en documentacion...")
    all_readmes = ["README.md"] + LOCAL_READMES
    emoji_errors = []

    for rel_path in all_readmes:
        full_path = os.path.join(ROOT_DIR, rel_path)
        if not os.path.exists(full_path):
            continue

        with open(full_path, "r", encoding="utf-8") as f:
            content = f.read()

        matches = EMOJI_PATTERN.findall(content)
        if matches:
            emoji_errors.append(f"{rel_path}: Emojis encontrados: {set(matches)}")

    if emoji_errors:
        for err in emoji_errors:
            print(f"  FALLO: {err}")
        return False

    print(f"  OK: 0 emojis detectados en los {len(all_readmes)} archivos de documentacion.")
    return True

def verify_no_forbidden_files():
    print("[4/6] Verificando ausencia de archivos prohibidos (AGENTS.md, GEMINI.md)...")
    forbidden_found = []

    for root, dirs, files in os.walk(ROOT_DIR):
        if ".git" in dirs:
            dirs.remove(".git")
        for file in files:
            if file in ("AGENTS.md", "GEMINI.md"):
                forbidden_found.append(os.path.join(root, file))

    if forbidden_found:
        print(f"  FALLO: Archivos prohibidos detectados: {forbidden_found}")
        return False

    print("  OK: Ningun archivo prohibido encontrado en el repositorio.")
    return True

def verify_production_exclusions():
    print("[5/6] Verificando reglas de exclusion de produccion (.dockerignore)...")
    expected_ignore_files = [
        ".dockerignore",
        "backend/.dockerignore",
        "rust-engine/.dockerignore",
        "frontend/.dockerignore",
    ]

    missing = []
    for rel_path in expected_ignore_files:
        full_path = os.path.join(ROOT_DIR, rel_path)
        if not os.path.exists(full_path):
            missing.append(rel_path)
        else:
            with open(full_path, "r", encoding="utf-8") as f:
                content = f.read()
            if "**/*README*.md" not in content and "*README*.md" not in content:
                print(f"  ADVERTENCIA: {rel_path} no excluye explicitamente patrones README.")

    if missing:
        print(f"  FALLO: Archivos .dockerignore faltantes: {missing}")
        return False

    print("  OK: Reglas de exclusion .dockerignore establecidas en raiz y subproyectos.")
    return True

def verify_dist_cleanliness():
    print("[6/6] Verificando que frontend/dist contenga 0 archivos markdown...")
    dist_dir = os.path.join(ROOT_DIR, "frontend/dist")
    if not os.path.exists(dist_dir):
        print("  ADVERTENCIA: frontend/dist no existe aun, omitiendo chequeo de dist.")
        return True

    markdown_files = []
    for root, dirs, files in os.walk(dist_dir):
        for file in files:
            if file.endswith(".md"):
                markdown_files.append(os.path.join(root, file))

    if markdown_files:
        print(f"  FALLO: Archivos markdown encontrados en frontend/dist: {markdown_files}")
        return False

    print(f"  OK: 0 archivos markdown detectados en {dist_dir}.")
    return True

def main():
    print("=" * 70)
    print("ReelForge - Verificador de Documentacion y Exclusiones de Produccion")
    print("=" * 70)

    results = [
        verify_root_readme(),
        verify_local_readmes(),
        verify_no_emojis(),
        verify_no_forbidden_files(),
        verify_production_exclusions(),
        verify_dist_cleanliness(),
    ]

    print("=" * 70)
    if all(results):
        print("RESULTADO: TODAS LAS COMPROBACIONES DE DOCUMENTACION Y EXCLUSION PASARON (6/6)")
        print("=" * 70)
        sys.exit(0)
    else:
        print("RESULTADO: AL MENOS UNA COMPROBACION FALLO.")
        print("=" * 70)
        sys.exit(1)

if __name__ == "__main__":
    main()
