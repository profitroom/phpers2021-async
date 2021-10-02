const opentracing = require("opentracing")
const mysql = require('mysql2/promise')
const boot = require('./boot').boot

async function main() {
    const [tracer, channel, appSpan] = await boot('consumer')

    const connectingSpan = tracer.startSpan('mysql-connecting', {childOf: appSpan})
    const pool = await initConnectionsPool()
    connectingSpan.finish()

    const consumingSpan = tracer.startSpan('consuming', {childOf: appSpan})

    process.on('SIGINT', async function () {
        console.log('flushing')
        consumingSpan.finish()
        appSpan.finish()
        await tracer.close()
        // process.exit()
    });

    let counter = 0
    channel.consume('message_queue', async function (message) {
        counter++
        const parentSpan = tracer.extract(opentracing.FORMAT_HTTP_HEADERS, message.properties.headers)
        const span = tracer.startSpan(message.fields.routingKey, {childOf: parentSpan})

        await processMessage(message, pool)

        await publishEvent(message, channel, tracer, span)

        channel.ack(message)

        span.finish()
    })

    async function processMessage(message, pool) {
        const msg = JSON.parse(message.content)
        const conn = await pool.getConnection()
        await conn.query('START TRANSACTION')
        const [rows] = await conn.query('SELECT value FROM data WHERE id=?', [msg.id])
        const currentValue = rows[0]['value']
        await conn.query('UPDATE data SET value=? WHERE id=?', [currentValue + msg.value, msg.id])
        await conn.query('COMMIT')
        conn.release()
    }

    async function publishEvent(message, channel, tracer, span) {
        const headers = []
        tracer.inject(span, opentracing.FORMAT_HTTP_HEADERS, headers)
        await channel.sendToQueue('events_queue', message.content, {headers: headers})
    }

    function initConnectionsPool() {
        return mysql.createPool({
            host: process.env.DB_HOST,
            database: process.env.DB_NAME,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            waitForConnections: true,
            connectionLimit: process.env.DB_POOL,
            queueLimit: 0
        })
    }
}

main()