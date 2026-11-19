#!/usr/bin/env python3
"""Construire le ZIP installable depuis HEAD : python3 scripts/build-release.py chemin.zip."""

import argparse
import hashlib
import io
from pathlib import Path
import shutil
import subprocess
import tempfile
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
UPSTREAM = "https://raw.githubusercontent.com/YahnisElsts/plugin-update-checker/a2db6871deec989a74e1f90fafc6d58ae526a879/vendor/"
DEPENDENCIES = {
    "Parsedown.php": "f50ac96282914f604012154405f2f946d5847e86669ca3da0a57f0977bd81153",
    "ParsedownModern.php": "8eb141fa55be71aa21e7dbeb077ae552af78b7a63b1af37307493f10d8327196",
    "PucReadmeParser.php": "7786172b3f63f6a558bc94dd2d4ddcf62ddc4565754e4d6f1cc2f9694145611a",
}


def build(destination: Path) -> None:
    # Les dépendances sont téléchargées à la construction, jamais à l’exécution du plugin.
    dependencies = {}
    for name, expected_hash in DEPENDENCIES.items():
        with urllib.request.urlopen(UPSTREAM + name, timeout=30) as response:
            content = response.read()
        if hashlib.sha256(content).hexdigest() != expected_hash:
            raise RuntimeError(f"Empreinte incorrecte pour {name}")
        dependencies[name] = content

    source = subprocess.check_output(
        ["git", "archive", "--format=zip", "--prefix=crom-link/", "HEAD"], cwd=ROOT
    )
    with tempfile.TemporaryDirectory(prefix="crom-link-release-") as workspace:
        workspace = Path(workspace)
        archive = workspace / "crom-link.zip"
        with zipfile.ZipFile(io.BytesIO(source)) as original, zipfile.ZipFile(
            archive, "w", compression=zipfile.ZIP_DEFLATED
        ) as output:
            for entry in original.infolist():
                output.writestr(entry, original.read(entry.filename))
            for name, content in dependencies.items():
                output.writestr(f"crom-link/plugin-update-checker/vendor/{name}", content)
        with zipfile.ZipFile(archive) as package:
            if package.testzip() is not None:
                raise RuntimeError("Archive ZIP corrompue")
            package.extractall(workspace / "verified")
        plugin = workspace / "verified" / "crom-link"
        # Vérifier le paquet extrait, pas seulement les dépendances du poste de développement.
        subprocess.run(["php", "tests/client-test.php"], cwd=plugin, check=True)
        subprocess.run(["php", "tests/update-checker-test.php"], cwd=plugin, check=True)
        subprocess.run(["node", "tests/editor-test.js"], cwd=plugin, check=True)
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(archive, destination)
    print(f"ZIP installable validé : {destination}")
    print(f"SHA256 : {hashlib.sha256(destination.read_bytes()).hexdigest()}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("destination", type=Path)
    build(parser.parse_args().destination.resolve())
