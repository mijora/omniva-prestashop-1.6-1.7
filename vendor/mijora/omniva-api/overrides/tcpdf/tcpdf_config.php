<?php
/* Enable overrides */
if(version_compare(_PS_VERSION_, '1.7', '=<')){
    define('K_TCPDF_EXTERNAL_CONFIG', true);
  }

/* Allow generate barcode image */
define('K_TCPDF_CALLS_IN_HTML', true);
