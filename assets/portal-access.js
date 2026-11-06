/* Invitation credentials stay in the fragment until copied into the POST form. */
(()=>{'use strict';const field=document.getElementById('df-invitation-code');if(!field)return;const m=/^#invite=([a-f0-9]{64})$/.exec(window.location.hash);if(m){field.value=m[1];history.replaceState(null,'',window.location.pathname+window.location.search);}})();
