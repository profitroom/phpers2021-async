const initTracer = require("jaeger-client").initTracer
const opentracing = require("opentracing")
const amqp = require('amqplib')
const dotenv = require('dotenv')

async function boot(processName) {
    const tracer = initTracer({
        serviceName: processName,
        reporter: {
            collectorEndpoint: 'http://localhost:14268/api/traces',
            // agentHost: 'localhost',
            // agentPort: 6832,
            // logSpans: true,
        },
        sampler: {
            type: 'const',
            param: 1,
        },
    }, {
        logger: {
            info: function logInfo(msg) {
                console.log('INFO ', msg)
            },
            error: function logError(msg) {
                console.log('ERROR', msg)
            }
        }
    })
    opentracing.initGlobalTracer(tracer);

    const appSpan = tracer.startSpan('process')

    dotenv.config({path:'../.env'})

    const span = tracer.startSpan('rabbitmq-connect', {childOf: appSpan})
    const conn = await amqp.connect('amqp://localhost')
    const channel = await conn.createChannel()
    // channel.qos(100, false)
    span.finish()

    return [tracer, channel, appSpan]
}

exports.boot = boot