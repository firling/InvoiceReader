<?php

namespace InvoiceReader;

class InvoiceReader
{
    public static function readInvoice($apiKey, $baseUrl, $imageB64) {
        if (!$apiKey) { throw new Exception("Api key is missing."); }
        if (!$baseUrl) { throw new Exception("Base url is missing."); }
        if (!$imageB64) { throw new Exception("Image (B64) is missing."); }

        if (substr($imageB64, 0, 4) == "data") {
          $imageData = base64_decode(explode(',', $imageB64)[1]);
        } else {
          $imageData = $imageB64;
        }

        //on crée la requete POST
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        // on récupère l'image envoyé depuis le front, on enlève le header du hash B64 (data:image/png;base64,)
        // et on decode le hash
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
           'Content-Type: application/octet-stream',
           'Ocp-Apim-Subscription-Key:'.$apiKey
        ));

        $headers = curl_exec($ch);
        curl_close($ch);

        $data = explode("\n",$headers);
        $requestUrl = "";
        if( !empty( $data ) ) {
          // on parcourt toutes les lignes du header de la réponse
          foreach( $data as $header_line ) {
            if( strstr($header_line, 'Operation-Location') ) {
              // on récuprère l'url pour récupérer la réponse
              $requestUrl = trim(str_replace('Operation-Location: ', '', $header_line));
            }
          }
        }


        if ($requestUrl != "") {
          $iteration = 0;
          $continue = true;
          $valid = false;
          while( $continue ) {
              sleep(1);
              $ch2 = curl_init($requestUrl);
              curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
              curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
              curl_setopt($ch2, CURLOPT_HTTPHEADER, array(
                 'Ocp-Apim-Subscription-Key:'.$apiKey
              ));
              $output2 = curl_exec($ch2);

              $result = json_decode($output2);

              $iteration++;
              if( $iteration === 11 ) {
                $continue = false;
              }
              if(!isset($result->status)) { continue; }
              if( $result->status == 'Succeeded' ) {
                $continue = false;
                $valid = true;
              }
          }
        } else {
          throw new Exception("Api didn't respond to the image sent.");
        }

        if(!$valid) {
          throw new Exception("Api didnt respond in time or the image sent isn't valid (max 4MB)");
        }


        $resultImageArr = $result->recognitionResult->lines;

        $resultArrLines = array();

        foreach($resultImageArr as $line) {
          array_push($resultArrLines, $line->text);
        }

        $arrResult = array();

        $arrResult["lines"] = $resultArrLines;

        $regex = "#(([0-2][0-9])|(3[0-1]))(-|/)((0[1-9])|(1[0-2]))(-|/)((\d{4})|(\d{2}))#"; // regex pour une date
        $priceSearch = false;
        $totalPrice = array();
        $lastValues = array();
        $tvaArray = array();
        foreach( $resultArrLines as $line ) {
          if(preg_match($regex, $line)) {
            preg_match($regex, $line, $resultDate);
            $arrResult["date"] = $resultDate[0];
          }
          // Si il y a montant, total, totaux dans la ligne, on commence à chercher le prix dans les lignes du dessous
          if(preg_match("#(montant)|(total)|(totaux)|(prix)#", strtolower($line))) {
            $priceSearch = true;
          }
          if($priceSearch) {
            //recherche du prix total
            if(preg_match("#[0-9]+(\.|,)[0-9]{2,2}(?!(\.|[0-9a-zA-Z]))#", $line)) {
              array_push($totalPrice, $line);
            }
            //recherche de la TVA
            if(preg_match("#%#", $line)){
              array_push($tvaArray, $line);
            }
          }

          // s'il y a un des 4 mots dans la ligne, on utilise la ligne comme nom pour la ndf
          if(preg_match("#(hotel)|(billet)|(car)|(bus)#", strtolower($line)) && !isset($arrResult["name"])) {
            $arrResult["name"] = $line;
          }

          //si il y a un code postal
          if(preg_match("#((0[1-9])|([1-8][0-9])|(9[0-5]))[0-9]{3}( |,)#", $line) && !isset($arrResult["name"])) {
            //on cherche me nom de l'endroit

            for($i=0; $i<sizeof($lastValues); $i++) {
              if(preg_match("#(^[0-9]{1,3}( |,).+)|(zone)|(facture)#", strtolower($lastValues[$i]))) {
                $arrResult["name"] = $lastValues[$i+1];
                break;
              }
            }
          }
          array_unshift($lastValues, $line);
        }

        $highestPrice = $totalPrice[0];
        foreach($totalPrice as $price) {
          if (floatval($price) > floatval($highestPrice) && floatval($price) < 9999) {
            $highestPrice = $price;
          }
        }

        preg_match('#([0-9]+(\.|,)[0-9]+)|([0-9]+)#', $highestPrice, $matches); // nombre entier ou nombre a virgule

        $arrResult["total"] = floatval(str_replace(",", ".", $matches[0]));
        // si on a pas trouvé de TVA avant
        $inversed = array_reverse($resultArrLines);

        $arrTva = array();

        foreach($inversed as $line) {
          // si on trouve un prix
          if(preg_match("#^[0-9]+((.|,)[0-9]+)? ?(eur|€)?$#", strtolower($line)) ) {
            if(isset($arrResult["total"]) && $arrResult["total"] > 0){
              // si le prix est inférieur au 21 % du prix total
              if (floatval($line)/$arrResult["total"] < 0.21 && !isset($arrResult["VAT"])) {
                $arrResult["VAT"] = floatval(str_replace(",", ".", $line));
              }
            } else {
              $arrResult["VAT"] = floatval(str_replace(",", ".", $line));
            }
          }
        }

        return $arrResult;
    }
}
