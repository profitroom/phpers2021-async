const opentracing = require("opentracing")
const mysql = require('mysql2/promise')
const boot = require('./boot').boot

async function main() {
    const [tracer, channel, appSpan] = await boot('consumer')

    const connectingSpan = tracer.startSpan('mysql-connecting', {childOf: appSpan})
    const pool = await initPool()
    connectingSpan.finish()

    const consumingSpan = tracer.startSpan('consuming', {childOf: appSpan})

    process.on('SIGINT', async function () {
        consumingSpan.finish()
        appSpan.finish()
        tracer.close()
        process.exit()
    });

    let counter = 0
    channel.consume('message_queue', async function (message) {
        counter++
        const parentSpan = tracer.extract(opentracing.FORMAT_HTTP_HEADERS, message.properties.headers)
        const span = tracer.startSpan(message.fields.routingKey, {
            childOf: consumingSpan,
            references: opentracing.childOf(parentSpan)
        })

        const msg = JSON.parse(message.content)
        // const conn = await pool.getConnection()
        // await conn.query('START TRANSACTION')
        // await conn.query('UPDATE data SET value=? WHERE id=?', [msg.value, msg.id])
        await pool.query('UPDATE data SET value=? WHERE id=?', [msg.value, msg.id])
        // await conn.query('COMMIT')
        const headers = []
        tracer.inject(span, opentracing.FORMAT_HTTP_HEADERS, headers)
        await channel.sendToQueue('events_queue', Buffer.from(JSON.stringify({
            id: msg.id,
            last_value: msg.value,
            counter: counter
        })), {headers: headers})
        channel.ack(message)

        span.finish()
    })
}

function initPool() {
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

main()