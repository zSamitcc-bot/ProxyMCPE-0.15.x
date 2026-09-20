<?php

namespace kuoto\event\server;

/**
 * Se lanza cuando un backend ha superado la autenticacion y ya esta registrado
 * en el ServerManager. No es cancelable: el servidor ya esta dentro.
 */
class ServerAuthenticatedEvent extends ServerEvent
{
}
