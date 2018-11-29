# Portail SNCF 

## Présentation de la page
Le portail de services @U.Gares est une interface utilisateur accessible depuis un navigateur Internet.

L'accès à ce portail nécessite l'authentification de l'utilisateur par l'application Glpi. Dans le cas du portail @U.Gares, l'authentification de l'utilisateur est réalisée sans qu'il soit nécessaire de présenter un nom d'utilisateur et un mot de passe grâce au mécanisme SSO mis en place entre l'application Glpi et les serveurs d'authentification de la SNCF.
 
Lorsque l'utilisateur est authentifié, il accède au portail qui se présente sous la forme d'une page découpée en plusieurs zones : 

 - un bandeau haut
 - un menu latéral
 - une zone de contenu variable selon la consultation en cours.

 img: portail-00.png
  
### Bandeau haut
Le bandeau haut présente:
 - le logo SNCF et le nom de l'application
 - le nom de l'utilisateur connecté
 - le bouton de déconnexion

Lorsque l'utilisateur souhaite quitter le portail, il lui suffit simplement de ferler la fenêtre ou l'onglet de son navigateur. Il peut également cliquer sur le bouton de déconnexion situé complètement à droite du bandeau haut.

Immédiatement à droite du logo et à gauche du titre de l'application, un bouton permet de replier le menu latéral de façon à agrandir la zone de contenu de la page. Ceci peut être particulièrement utile pour un utilisateur qui ne dispose que d'un écran de taille moyenne. Sur un écran de petite taille (eg. Smartphone), la page est systématiquement présentée avec le menu replié.

 img: portail-00-2.png

### Menu latéral
Le menu latéral présente les choix qui sont proposés à l'utilisateur connecté.

Le portail permet à l'utilisateur connecté de :
 - demander une assistance
 - consulter les demandes qu'il a déjà formulées
 - consulter toutes les demandes en cours
 
Ces princpales fonctionnalités sont documentées dans le chapitre suivant.


## Fonctionnalités
### Formuler une demande

Le choix "Demander une assistance" est le choix par défaut présenté à l'utilisateur lors de sa connexion. La zone de contenu du portail présente alors : 

 - les catégories de demandes disponibles - à gauche
 - un moteur de recherche - en haut 
 - la liste des formulaires utilisables - en bas à droite

 img portail-01-1.png

L'objectif est de permettre à l'utilisateur de choisir facilement le formulaire adapté à sa demande. Pour cela il peut naviguer dans les catégories disponibles ou rechercher un texte.

Les formulaires présentés sur cette page sont présentés de façon à ce que les plus fréquemment utilisés soitent proposée en premier à l'utilisateur.

#### Arborescence des catégories

Naviguer dans les catégories proposées permet d'affiner la sélection et de réduire la liste des formulaires. En cliquant sur le nom d'une catégorie, le portail présente la liste des formulaires de cette catégorie et, s'il en existe, les sous catégories disponibles.

Cliquer sur "Voir tous" ramène l'utilisateur au sommet de l'arboescence des catégories.

 img portail-01-1.png

   Ici on voit que seule la catégorie "3 - Autres demandes" propose des sous catégories.

 img portail-01-2.png

   Ici on voit que l'utilisateur a sélectionné la catégorie "3 - Autres demandes" et que le portail ne propose plus que deux formulaires.


#### Moteur de recherche

Le moteur de recherche permet à l'utilisateur de saisir un ou des mots qui vont être recherchés dans les intitulés des formulaires disponibles pour affiner la liste des formulaires proposés.

 img portail-01-3.png

   Ici on voit que l'utilisateur a saisi "vid" dans le moteur de recherche et que le portail ne propose plus qu'un seul formulaire.


### Consulter mes incidents 

Le choix "Consulter mes incidents" permet à l'utilisateur d'accéder à la liste de tous les incidents qu'il a lui-même signalé. La zone de contenu du portail présente alors une liste des incidents avec, pour chacun d'eux: son état, son titre, la date de son ouverture, ... [à compléter]

 img portail-02-1.png

La liste des incidents présentée par défaut est la liste des incidents ouverts par l'utilisateur connecté

Cliquer sur le titre d'une des colonnes trie la liste des incidents selon le contenu de cette colonne (tri ascendant=). Un nouveau clic sur le même titre inverse le sens du tri (tri descendant). 

Au dessus de cette liste, un moteur de recherche permet à l'utilisateur d'affiner la présentation en modifiant les critères de recherche.

#### Moteur de recherche

Le moteur de recherche permet à l'utilisateur de saisir un ou des mots qui vont être recherchés dans les intitulés des formulaires disponibles pour affiner la liste des formulaires proposés.



### Consulter tous les incidents 

Le choix "Consulter tous les incidents" permet à l'utilisateur d'accéder à la liste de tous les incidents actuellement en cours pour l'ensemble des gares.




### Consulter l'aide en ligne

Le choix Aide en ligne du menu ouvre une nouvelle page ou un nouvel onglet dans le navigateur de l'utiilsateur pour lui présenter l'aide à l'utilisation du portail. Il s'agit d'un document Pdf que l'utilisateur pourra consulter mais également télécharger grâce à son navigateur.

