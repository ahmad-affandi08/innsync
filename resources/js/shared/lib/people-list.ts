export type ParsedPerson = { name: string; email: string }

export type ParsedPeople = { people: ParsedPerson[]; invalid: string[] }

const EMAIL = /^[^\s@<>,;]+@[^\s@<>,;]+\.[^\s@<>,;]+$/

/**
 * Reads a pasted list of people, one per line: `Name, email`, `Name; email`, `Name<TAB>email` (a spreadsheet), `Name <email>` or just an email
 * (the name is then the part before the @). Blank lines are ignored; a line without a usable email is reported, not guessed. The same email twice is kept once.
 */
export function parsePeople(text: string): ParsedPeople {
    const people: ParsedPerson[] = []
    const invalid: string[] = []
    const seen = new Set<string>()

    for (const raw of text.split(/\r?\n/)) {
        const line = raw.trim()

        if (line === '') continue

        let name = ''
        let email = ''
        const angle = line.match(/^(.*?)\s*<\s*([^<>\s]+)\s*>\s*$/)

        if (angle !== null) {
            name = angle[1] ?? ''
            email = angle[2] ?? ''
        } else {
            const parts = line.split(/[\t,;]/).map((p) => p.trim()).filter((p) => p !== '')
            const at = parts.find((p) => EMAIL.test(p))

            if (at !== undefined) {
                email = at
                name = parts.filter((p) => p !== at).join(' ')
            } else if (EMAIL.test(line)) {
                email = line
            }
        }

        if (!EMAIL.test(email)) {
            invalid.push(line)
            continue
        }

        const key = email.toLowerCase()

        if (seen.has(key)) continue

        seen.add(key)
        people.push({ name: name.trim() !== '' ? name.trim() : (email.split('@')[0] ?? email), email })
    }

    return { people, invalid }
}
