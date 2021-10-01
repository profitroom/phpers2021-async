# Wydajny i asynchroniczny PHP
Krzysztof Kowalczykiewicz / [Profitroom](https://www.profitroom.com) / PHPers Summit 2021

## Założenia eksperymentu
- serwis `producer` generuje 10000 zleceń wykonania operacji
- serwis `consumer` przetwarzają zakolejkowane zlecenia, generując event po zakończeniu
- serwis `listener` nasłuchuje na eventy zakończenia i je zlicza/podsumowuje
- procesy są instrumentowane przy pomocy Jaegera

## Implementacja PHP

```
cd php
composer install
php producer.php &
php listener.php &
php consumer.php & 
```

## Implementacja Node.js
```
cd nodejs
npm install
node producer &
# node listener & # missing 
node consumer & 
```

## Implementacja Swoole

## Implementacja ReactPHP

## Implementacja GoLang

## Jaeger
### Instalacja
```
docker run -d --name jaeger \
  -e COLLECTOR_ZIPKIN_HOST_PORT=:9411 \
  -p 5775:5775/udp \
  -p 6831:6831/udp \
  -p 6832:6832/udp \
  -p 5778:5778 \
  -p 16686:16686 \
  -p 14268:14268 \
  -p 14250:14250 \
  -p 9411:9411 \
  jaegertracing/all-in-one:1.26
```
### Uruchomienie
http://localhost:16686/

## Linki
- []()