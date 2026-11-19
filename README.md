# crom-link
Plugin Wordpress pour faire le lien avec le calendrier 

## Construire une release

Après avoir commité les changements et incrémenté la version :

```sh
python3 scripts/build-release.py /chemin/crom-link-x.y.z.zip
```

La construction utilise HEAD, télécharge les dépendances officielles du module de mise à jour à une révision figée, vérifie leurs empreintes et teste le ZIP extrait. Elle nécessite Python 3, PHP, Node.js et un accès HTTPS à GitHub. Publier ce ZIP comme pièce jointe de la release ; une archive `git archive` seule ne contient pas les dépendances. Les dépendances générées restent hors Git.
