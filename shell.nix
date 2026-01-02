{ pkgs ? import <nixpkgs> {} }:

pkgs.mkShell {
  buildInputs = [
    pkgs.php
    pkgs.git
  ];

  shellHook = ''
    pest() {
      php artisan optimize:clear 2>/dev/null

      if [ $# -eq 0 ]; then
        ./vendor/bin/pest 
      else
        ./vendor/bin/pest --filter="$*"
      fi
    }

    export -f pest
  '';
}

