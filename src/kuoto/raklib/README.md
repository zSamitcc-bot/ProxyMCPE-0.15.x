# `kuoto\raklib`

Utilidades de bajo nivel para trabajar con datos binarios, tomadas/inspiradas en la librería **RakLib** usada por PocketMine y Synapse.

## Clases

### `Binary`
Funciones estáticas para leer y escribir tipos primitivos sobre cadenas de bytes: bytes con/sin signo, enteros de distintos tamaños, floats, etc. Es la base para (de)serializar los paquetes del protocolo.

### `BinaryStream`
Envuelve un buffer de bytes y expone una API tipo "stream" (con puntero de lectura/escritura interno) construida sobre `Binary`, usada por las clases de `kuoto\protocol` para leer y escribir sus campos de forma secuencial.

### `BinaryDataException`
Excepción lanzada cuando la lectura/escritura de datos binarios falla (por ejemplo, datos truncados o fuera de rango).

## Relación con el resto del proyecto

Es una dependencia transversal usada principalmente por `kuoto\protocol` (serialización de paquetes) y por `kuoto\network` (parsing de tramas RakNet a bajo nivel).
