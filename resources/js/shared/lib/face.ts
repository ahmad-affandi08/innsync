/**
 * Turns a photo of a face into the 128 numbers the server compares (face matching for attendance). It runs in the person's own browser with a model served from this site, so the photo is not sent
 * anywhere for this; the library and the model (about 7 MB) are fetched the first time it is needed, never for pages that do not match faces.
 */
import type * as FaceApi from '@vladmandic/face-api';

const MODEL_PATH = '/face-models';
const MAX_SIDE = 640;

let loading: Promise<typeof FaceApi> | null = null;

function loadFace(): Promise<typeof FaceApi> {
    loading ??= (async () => {
        const faceapi = await import('@vladmandic/face-api');

        // The library prefers a WebAssembly backend that needs files this site does not serve, so the backend is chosen here: the graphics chip when the phone has one, the processor otherwise.
        const tf = faceapi.tf as unknown as { setBackend(name: string): Promise<boolean>; ready(): Promise<void> };

        for (const backend of ['webgl', 'cpu']) {
            try {
                if (await tf.setBackend(backend)) break;
            } catch {
                // try the next one
            }
        }

        await tf.ready();
        await Promise.all([faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_PATH), faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_PATH), faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_PATH)]);

        return faceapi;
    })().catch((error: unknown) => {
        loading = null;
        throw error;
    });

    return loading;
}

/** The photo, turned upright and no larger than the detector needs, so a 12-megapixel selfie is not worked on at full size. */
async function smallCanvas(file: File): Promise<HTMLCanvasElement> {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');

    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    return canvas;
}

const round6 = (n: number) => Math.round(n * 1_000_000) / 1_000_000;

/** The descriptor of the one face in the photo; null when there is no face, or more than one is too unclear to tell apart. Throws when the model cannot be loaded. */
export async function descriptorOf(file: File): Promise<number[] | null> {
    const faceapi = await loadFace();
    const canvas = await smallCanvas(file);
    const found = await faceapi.detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 })).withFaceLandmarks().withFaceDescriptor();

    return found === undefined ? null : Array.from(found.descriptor, round6);
}
