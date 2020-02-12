# Invoice Reader

### `composer require firling/invoicereader`

This package is using the Microsoft Cognitive Services API, you'll need to provide an API key, as well as a base url.

## function: readInvoice($apiKey, $baseUrl, $imageB64)

###### Factors
- $apiKey: key of the Microsoft Cognitive Services API
- $baseUrl: base url of the Microsoft Cognitive Services API
- $imageB64: image hashed in base64.

###### Returns
This function return an array / dict of different value :
- lines: array of every line the microsoft api returns
- name: name of the invoice (a bit random)
- date: date of the invoice
- total: total price of the invoice
- VAT: VAT of the invoice (if there's no vat on the invoice, it'll return what it thinks is a vat)
