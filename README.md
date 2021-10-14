# Plugin trafficmirror

Ce plugin est une extension pour **Jeedom**.

Ce plugin permet de dupliquer des flux TCP ou UDP vers un serveur "miroir". Le plugin agit comme un proxy, c'est a dire que les clients vont envoyer leurs requêtes vers le proxy plutôt que vers le serveur destination, et le plugin se chargera de transmettre cette requête au serveur, et au miroir.

Grâce à ce plugin, vous pouvez renvoyer des informations a un serveur de tests, ou comme c'est mon cas, dupliquer les informations pour réaliser le traitement a la fois dans Jeedom, et sur le serveur réel. En effet, ma centrale d'alarme ne me permet pas d'envoyer les meme informations a deux serveurs différents. Donc, ma centrale envoie les informations a trafficmirror, qui se charge de les transmettre a Jeedom et a la télésurveillance. Bien sur, il faut dans ce cas de la redondance pour s'assurer que la centrale pourra toujours communiquer avec la télésurveillance si votre plugin trafficmirror est injoignable.
