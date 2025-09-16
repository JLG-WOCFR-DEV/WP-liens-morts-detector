jQuery(document).ready(function($) {
    var messages = window.blcAdminMessages || {};
    var editPromptMessage = messages.editPromptMessage || "Entrez la nouvelle URL pour :\n%s";
    var editPromptDefault = messages.editPromptDefault || 'https://';
    var unlinkConfirmation = messages.unlinkConfirmation || "Êtes-vous sûr de vouloir supprimer ce lien ? Le texte sera conservé.";
    var errorPrefix = messages.errorPrefix || 'Erreur : ';

    /**
     * Gère le clic sur le bouton "Modifier le lien".
     */
    // On utilise la délégation d'événements pour s'assurer que ça fonctionne même avec la pagination AJAX (si on l'ajoute un jour)
    $('#the-list').on('click', '.blc-edit-link', function(e) {
        e.preventDefault();

        var linkElement = $(this);
        var oldUrl = linkElement.data('url');
        var postId = linkElement.data('postid');
        var nonce = linkElement.data('nonce');

        // Affiche une boîte de dialogue pour demander la nouvelle URL
        var promptMessage = editPromptMessage.replace('%s', oldUrl);
        var newUrl = prompt(promptMessage, editPromptDefault);

        // Si l'utilisateur a entré une nouvelle URL et n'a pas annulé
        if (newUrl && newUrl !== oldUrl) {
            // Grise la ligne pour montrer qu'une action est en cours
            linkElement.closest('tr').css('opacity', 0.5);

            // Envoie la requête AJAX à WordPress
            $.post(ajaxurl, {
                action: 'blc_edit_link',
                post_id: postId,
                old_url: oldUrl,
                new_url: newUrl,
                _ajax_nonce: nonce
            }, function(response) {
                if (response.success) {
                    // Si la modification a réussi, on fait disparaître la ligne
                    linkElement.closest('tr').fadeOut(300, function() { $(this).remove(); });
                } else {
                    // S'il y a une erreur, on l'affiche et on remet la ligne en état normal
                    alert(errorPrefix + response.data.message);
                    linkElement.closest('tr').css('opacity', 1);
                }
            });
        }
    });

    /**
     * Gère le clic sur le bouton "Dissocier".
     */
    $('#the-list').on('click', '.blc-unlink', function(e) {
        e.preventDefault();

        var linkElement = $(this);
        var urlToUnlink = linkElement.data('url');
        var postId = linkElement.data('postid');
        var nonce = linkElement.data('nonce');

        // Demande une confirmation avant de supprimer le lien
        if (confirm(unlinkConfirmation)) {
            linkElement.closest('tr').css('opacity', 0.5);

            $.post(ajaxurl, {
                action: 'blc_unlink',
                post_id: postId,
                url_to_unlink: urlToUnlink,
                _ajax_nonce: nonce
            }, function(response) {
                if (response.success) {
                    linkElement.closest('tr').fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert(errorPrefix + response.data.message);
                    linkElement.closest('tr').css('opacity', 1);
                }
            });
        }
    });
});
